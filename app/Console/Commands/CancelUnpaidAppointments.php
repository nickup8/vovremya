<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\AppointmentStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CancelUnpaidAppointments extends Command
{
    protected $signature = 'appointments:cancel-unpaid';

    protected $description = 'Cancel PendingPayment appointments that exceeded deposit timeout';

    public function handle(AppointmentStatusService $statusService): int
    {
        $now = Carbon::now();

        $appointments = Appointment::query()
            ->where('status', AppointmentStatus::PendingPayment)
            ->with('master')
            ->get()
            ->filter(function (Appointment $appointment) use ($now) {
                $timeout = $appointment->master->deposit_timeout ?? 15;

                return $appointment->created_at->copy()->addMinutes($timeout)->lt($now);
            });

        $cancelled = 0;

        foreach ($appointments as $appointment) {
            try {
                $statusService->transition($appointment, AppointmentStatus::Cancelled);
                $cancelled++;
            } catch (\Exception $e) {
                Log::warning('Failed to cancel unpaid appointment', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($cancelled > 0) {
            Log::info("Cancelled {$cancelled} unpaid appointments (timeout exceeded)");
        }

        $this->info("Cancelled {$cancelled} unpaid appointments.");

        return self::SUCCESS;
    }
}
