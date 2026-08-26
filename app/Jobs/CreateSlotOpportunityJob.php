<?php

namespace App\Jobs;

use App\DTOs\AppointmentWindowFreed;
use App\Services\SlotOpportunityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateSlotOpportunityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        private AppointmentWindowFreed $window,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SlotOpportunityService $service): void
    {
        $result = $service->createFromFreedWindow(
            originEventId: $this->window->originEventId,
            chainId: $this->window->chainId,
            workspaceId: $this->window->workspaceId,
            masterId: $this->window->masterId,
            masterServiceId: $this->window->masterServiceId,
            sourceAppointmentId: $this->window->sourceAppointmentId,
            sourceType: $this->window->sourceType,
            startTime: $this->window->startTime,
            duration: $this->window->duration,
        );

        if ($result === null) {
            Log::info('[AutoFill] Skipped past window', [
                'origin_event_id' => $this->window->originEventId,
                'start_time' => $this->window->startTime->toIso8601String(),
            ]);
        }
    }
}
