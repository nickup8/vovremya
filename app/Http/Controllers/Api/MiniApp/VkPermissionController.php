<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentNotificationEventType;
use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Services\MiniAppClientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VkPermissionController extends Controller
{
    public function __invoke(
        Request $request,
        MiniAppClientResolver $resolver,
    ): JsonResponse {
        $request->validate([
            'appointment_id' => 'required|string',
            'granted' => 'required|boolean',
        ]);

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $clientIds = $resolver->resolveClientIds($request);

        $appointment = Appointment::with(['client'])->find($request->input('appointment_id'));

        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 404);
        }

        if ($appointment->client_id === null || ! $clientIds->contains($appointment->client_id)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $client = $appointment->client;

        if (! $client) {
            return response()->json(['error' => 'client_not_found'], 404);
        }

        $granted = $request->boolean('granted');

        if ($granted) {
            if ($client->vk_messages_allowed !== true) {
                $client->update(['vk_messages_allowed' => true]);

                // Если подтверждение брони не ушло из-за no_channel/failed —
                // переставляем job той же идентичности события (версию не
                // двигаем: это не новое изменение записи, а снятие запрета).
                $this->retryUndeliveredBookingConfirmed($appointment);
            }
        } else {
            if ($client->vk_messages_allowed === null) {
                $client->update(['vk_messages_allowed' => false]);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function retryUndeliveredBookingConfirmed(Appointment $appointment): void
    {
        $events = AppointmentNotificationEvent::query()
            ->where('appointment_id', $appointment->getKey())
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->orderByDesc('event_version')
            ->get();

        foreach ($events as $event) {
            $undelivered = $event->deliveries()
                ->whereIn('status', [
                    AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                    AppointmentNotificationDelivery::STATUS_FAILED,
                ])
                ->get();

            if ($undelivered->isEmpty()) {
                continue;
            }

            $undelivered->each->delete();

            SendAppointmentNotificationJob::dispatch($event->getKey());

            break; // достаточно одного последнего события
        }
    }
}
