<?php

namespace App\Listeners;

use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\AppointmentStatusChanged;
use Illuminate\Support\Facades\Cache;

class FlushAvailabilityCache
{
    public function handle(AppointmentCreated|AppointmentStatusChanged|AppointmentRescheduled $event): void
    {
        $masterId = $event->appointment->master_id;

        try {
            Cache::tags(["availability:{$masterId}"])->flush();
        } catch (\Throwable) {
            // Cache::tags() не поддерживается драйвером — пропускаем
        }
    }
}
