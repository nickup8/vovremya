<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\SlotRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SlotRequestService
{
    public function createOrUpdateEarlierRequest(
        Appointment $appointment,
        Client $client,
        string $dateFrom,
        string $dateTo,
        string $timeFrom,
        string $timeTo,
        SlotRequestDeliveryChannel $deliveryChannel,
        SlotRequestSource $requestSource,
    ): SlotRequest {
        $this->validateAppointmentEligibility($appointment, $client);
        $this->validateChannelAvailability($client, $deliveryChannel);

        $master = $appointment->master;
        $timezone = $master->getTimezone();

        $this->validateTimeConstraints($timeFrom, $timeTo, $dateFrom, $dateTo, $appointment, $timezone);

        return DB::transaction(function () use (
            $appointment, $client, $dateFrom, $dateTo, $timeFrom, $timeTo,
            $deliveryChannel, $requestSource, $timezone,
        ) {
            $existing = SlotRequest::query()
                ->where('appointment_id', $appointment->id)
                ->where('type', SlotRequestType::Earlier)
                ->where('status', SlotRequestStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->handleExistingRequest(
                    $existing, $appointment, $dateFrom, $dateTo, $timeFrom, $timeTo,
                    $deliveryChannel, $requestSource, $timezone,
                );
            }

            return $this->createNewRequest(
                $appointment, $client, $dateFrom, $dateTo, $timeFrom, $timeTo,
                $deliveryChannel, $requestSource, $timezone,
            );
        });
    }

    public function expire(SlotRequest $request): SlotRequest
    {
        if ($request->status === SlotRequestStatus::Expired) {
            return $request;
        }

        if ($request->status !== SlotRequestStatus::Active) {
            throw new \DomainException(
                "Cannot expire request with status [{$request->status->value}]. Only active requests can be expired."
            );
        }

        $request->update([
            'status' => SlotRequestStatus::Expired,
            'expired_at' => now(),
        ]);

        return $request->refresh();
    }

    public function fulfill(SlotRequest $request): SlotRequest
    {
        if ($request->status === SlotRequestStatus::Fulfilled) {
            return $request;
        }

        if ($request->status !== SlotRequestStatus::Active) {
            throw new \DomainException(
                "Cannot fulfill request with status [{$request->status->value}]. Only active requests can be fulfilled."
            );
        }

        $request->update([
            'status' => SlotRequestStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);

        return $request->refresh();
    }

    public function cancel(SlotRequest $request, Client $client): SlotRequest
    {
        if ($request->client_id !== $client->id) {
            throw new \DomainException('Only the owning client can cancel this request.');
        }

        if ($request->status !== SlotRequestStatus::Active) {
            return $request;
        }

        $request->update([
            'status' => SlotRequestStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $request->refresh();
    }

    private function validateAppointmentEligibility(Appointment $appointment, Client $client): void
    {
        if ($appointment->client_id !== $client->id) {
            throw new \DomainException('Appointment does not belong to this client.');
        }

        $allowedStatuses = [AppointmentStatus::Booked];
        if (! in_array($appointment->status, $allowedStatuses, true)) {
            throw new \DomainException("Appointment status [{$appointment->status->value}] is not eligible for earlier request.");
        }

        $master = $appointment->master;
        $tz = $master->getTimezone();
        $appointmentLocal = $appointment->start_time->timezone($tz);

        if ($appointmentLocal->isPast()) {
            throw new \DomainException('Cannot create earlier request for a past appointment.');
        }

        if ($appointment->master_service_id === null) {
            throw new \DomainException('Appointment does not have a master service.');
        }

        if ($appointment->masterService === null) {
            throw new \DomainException('Referenced master service does not exist.');
        }

        if (! $appointment->masterService->is_active) {
            throw new \DomainException('Master service is currently inactive.');
        }
    }

    private function validateChannelAvailability(Client $client, SlotRequestDeliveryChannel $channel): void
    {
        if ($channel === SlotRequestDeliveryChannel::Telegram && empty($client->telegram_id)) {
            throw new \DomainException('Client does not have a Telegram identity for delivery.');
        }

        if ($channel === SlotRequestDeliveryChannel::Max && empty($client->max_id)) {
            throw new \DomainException('Client does not have a MAX identity for delivery.');
        }
    }

    private function validateTimeConstraints(
        string $timeFrom,
        string $timeTo,
        string $dateFrom,
        string $dateTo,
        Appointment $appointment,
        string $timezone,
    ): void {
        $appointmentLocal = $appointment->start_time->timezone($timezone);
        $appointmentDate = $appointmentLocal->format('Y-m-d');

        if ($dateFrom > $dateTo) {
            throw new \DomainException('date_from must be <= date_to.');
        }

        if ($dateTo > $appointmentDate) {
            throw new \DomainException('date_to must not be after the appointment date.');
        }

        if ($timeFrom >= $timeTo) {
            throw new \DomainException('time_from must be < time_to.');
        }

        $duration = $this->effectiveDuration($appointment);

        $startMinutes = $this->timeToMinutes($timeFrom);
        $endMinutes = $this->timeToMinutes($timeTo);
        $windowMinutes = $endMinutes - $startMinutes;

        if ($windowMinutes < $duration) {
            throw new \DomainException('Time window is too short for the appointment duration.');
        }

        if (! $this->hasEarlierPoint($dateFrom, $timeFrom, $duration, $appointmentLocal, $appointmentDate)) {
            throw new \DomainException('No full appointment can fit before the source appointment.');
        }
    }

    private function hasEarlierPoint(
        string $dateFrom,
        string $timeFrom,
        int $duration,
        \Carbon\CarbonInterface $appointmentLocal,
        string $appointmentDate,
    ): bool {
        $appointmentMinutes = $appointmentLocal->hour * 60 + $appointmentLocal->minute;

        if ($dateFrom < $appointmentDate) {
            return true;
        }

        if ($dateFrom > $appointmentDate) {
            return false;
        }

        $earliestStartMinutes = $this->timeToMinutes($timeFrom);

        return ($earliestStartMinutes + $duration) <= $appointmentMinutes;
    }

    private function handleExistingRequest(
        SlotRequest $existing,
        Appointment $appointment,
        string $dateFrom,
        string $dateTo,
        string $timeFrom,
        string $timeTo,
        SlotRequestDeliveryChannel $deliveryChannel,
        SlotRequestSource $requestSource,
        string $timezone,
    ): SlotRequest {
        if ($existing->appointment_start_time_snapshot !== null
            && $existing->appointment_start_time_snapshot->ne($appointment->start_time)
        ) {
            $existing->update([
                'status' => SlotRequestStatus::Expired,
                'expired_at' => now(),
            ]);

            return $this->createNewRequest(
                $appointment, $existing->client, $dateFrom, $dateTo, $timeFrom, $timeTo,
                $deliveryChannel, $requestSource, $timezone,
            );
        }

        $existing->update([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'delivery_channel' => $deliveryChannel,
            'request_source' => $requestSource,
            'timezone' => $timezone,
            'expires_at' => $this->calculateExpiresAt($appointment, $dateTo, $timeTo, $timezone),
        ]);

        return $existing->refresh();
    }

    private function createNewRequest(
        Appointment $appointment,
        Client $client,
        string $dateFrom,
        string $dateTo,
        string $timeFrom,
        string $timeTo,
        SlotRequestDeliveryChannel $deliveryChannel,
        SlotRequestSource $requestSource,
        string $timezone,
    ): SlotRequest {
        return SlotRequest::create([
            'workspace_id' => $appointment->workspace_id ?? $client->workspace_id,
            'master_id' => $appointment->master_id,
            'client_id' => $client->id,
            'appointment_id' => $appointment->id,
            'master_service_id' => $appointment->master_service_id,
            'type' => SlotRequestType::Earlier,
            'status' => SlotRequestStatus::Active,
            'request_source' => $requestSource,
            'delivery_channel' => $deliveryChannel,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'timezone' => $timezone,
            'appointment_start_time_snapshot' => $appointment->start_time,
            'expires_at' => $this->calculateExpiresAt($appointment, $dateTo, $timeTo, $timezone),
        ]);
    }

    private function calculateExpiresAt(
        Appointment $appointment,
        string $dateTo,
        string $timeTo,
        string $timezone,
    ): \Carbon\CarbonInterface {
        $appointmentExpiry = $appointment->start_time->copy();

        $windowEnd = \Illuminate\Support\Carbon::parse("{$dateTo} {$timeTo}", $timezone)->setTimezone('UTC');

        return $appointmentExpiry->lt($windowEnd) ? $appointmentExpiry : $windowEnd;
    }

    private function effectiveDuration(Appointment $appointment): int
    {
        if ($appointment->duration !== null) {
            return (int) $appointment->duration;
        }

        if ($appointment->masterService !== null) {
            return (int) $appointment->masterService->effective_duration;
        }

        return 60;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) $parts[0]) * 60 + ((int) $parts[1]);
    }
}
