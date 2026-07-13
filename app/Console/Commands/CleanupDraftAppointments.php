<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\AppointmentStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupDraftAppointments extends Command
{
    protected $signature = 'appointments:cleanup-drafts';

    protected $description = 'Cancel orphaned draft appointments (client_id is null, older than 15 minutes)';

    public function handle(AppointmentStatusService $statusService): int
    {
        $threshold = now()->subMinutes(15);

        $appointments = Appointment::query()
            ->whereNull('client_id')
            ->where('created_at', '<', $threshold)
            ->get();

        $cancelled = 0;

        foreach ($appointments as $appointment) {
            try {
                $statusService->transition($appointment, AppointmentStatus::Cancelled);
                $cancelled++;
            } catch (\Exception $e) {
                Log::warning('Failed to cancel draft appointment', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($cancelled > 0) {
            Log::info("Cleaned up {$cancelled} abandoned draft appointments");
        }

        $this->info("Cleaned up {$cancelled} draft appointments.");

        return self::SUCCESS;
    }
}
