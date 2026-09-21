<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentVisitConfirmed;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Support\Facades\Log;

class AppointmentVisitConfirmationService
{
    /**
     * Подтверждение визита клиентом.
     *
     * @return array{result: 'ok'|'not_found'|'not_available'|'already'}
     */
    public function confirm(Appointment $appointment, Client $client): array
    {
        if ($appointment->client_id !== $client->id) {
            return ['result' => 'not_found'];
        }

        if ($appointment->status !== AppointmentStatus::Booked) {
            return ['result' => 'not_available'];
        }

        $affected = $appointment->newQuery()
            ->where('id', $appointment->id)
            ->whereNull('client_confirmed_at')
            ->update(['client_confirmed_at' => now()]);

        if ($affected === 0) {
            return ['result' => 'already'];
        }

        $appointment->refresh();

        broadcast(new AppointmentVisitConfirmed(
            $appointment->fresh()->load(['client'])
        ));

        $tz = $appointment->master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        try {
            app(MasterNotificationService::class)
                ->sendToMaster($appointment->master, __('bot.master.visit_confirmed', [
                    'client' => $client->name ?? __('bot.fallback.client_name'),
                    'date' => $date,
                    'time' => $time,
                ]));
        } catch (\Throwable $e) {
            Log::warning('AppointmentVisitConfirmation: failed to notify master', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['result' => 'ok'];
    }
}
