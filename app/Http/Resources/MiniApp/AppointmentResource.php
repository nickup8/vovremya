<?php

namespace App\Http\Resources\MiniApp;

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
            ] : null,
            'can_cancel' => $canCancel,
        ];
    }
}
