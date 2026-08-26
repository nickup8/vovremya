<?php

namespace App\Http\Resources\MiniApp;

use App\Enums\AppointmentStatus;
use App\Exceptions\CancellationNotAllowedException;
use App\Services\AppointmentStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $master = $this->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        // can_cancel: проверяем через assertCanCancel
        $canCancel = false;

        if ($this->status?->value === 'booked' && $this->start_time?->isFuture()) {
            try {
                app(AppointmentStatusService::class)->assertCanCancel($this->resource);
                $canCancel = true;
            } catch (CancellationNotAllowedException) {
                $canCancel = false;
            }
        }

        // autofill_available: Booked + future + master has AutoFill enabled
        $autofillAvailable = $this->status === AppointmentStatus::Booked
            && $this->start_time?->isFuture()
            && $master?->isAutoFillEnabled();

        // earlier_request: active slot request (eager loaded)
        $activeRequest = $this->activeSlotRequest;
        $earlierRequest = null;

        if ($activeRequest) {
            $earlierRequest = [
                'id' => $activeRequest->id,
                'date_from' => $activeRequest->date_from->format('Y-m-d'),
                'date_to' => $activeRequest->date_to->format('Y-m-d'),
                'time_from' => substr($activeRequest->time_from, 0, 5),
                'time_to' => substr($activeRequest->time_to, 0, 5),
                'status' => $activeRequest->status->value,
            ];
        }

        return [
            'id' => $this->id,
            'service' => $this->display_name,
            'price' => $this->display_price,
            'status' => $this->status?->value,
            'start_at' => $this->start_time?->timezone($tz)->format('Y-m-d H:i'),
            'start_at_human' => $this->start_time?->timezone($tz)->format('d.m.Y H:i'),
            'master' => $master ? [
                'name' => $master->name,
                'address' => $master->address,
                'phone' => $master->phone,
                'master_slug' => $master->master_slug,
            ] : null,
            'can_cancel' => $canCancel,
            'autofill_available' => $autofillAvailable,
            'earlier_request' => $earlierRequest,
        ];
    }
}
