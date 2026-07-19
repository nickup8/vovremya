<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMasterNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        private Appointment $appointment,
    ) {}

    public function handle(MasterNotificationService $notificationService): void
    {
        $notificationService->sendNewAppointmentAlert($this->appointment);
    }
}
