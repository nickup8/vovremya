<?php

namespace App\Services;

use App\Models\MasterService;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateServiceAction
{
    /**
     * Тройная атомарная запись: services (legacy) + service_catalog + master_service.
     * Прозрачно для одиночки (I-C2). Возвращает созданный Service (legacy, для виджета).
     *
     * @param User $master  targetMaster (владелец услуги)
     * @param array{title:string, price:float|string, duration_minutes:int} $data
     */
    public function execute(User $master, array $data): Service
    {
        return DB::transaction(function () use ($master, $data) {
            // 1. LEGACY services (виджет/booking flow читают отсюда — НЕ ломаем)
            $service = $master->services()->create([
                'title'            => $data['title'],
                'price'            => $data['price'],
                'duration_minutes' => $data['duration_minutes'],
            ]);

            // 2. service_catalog (Уровень 1) — идемпотентно по (workspace_id, title)
            $catalog = ServiceCatalog::firstOrCreate(
                [
                    'workspace_id' => $master->workspace_id,
                    'title'        => $data['title'],
                ],
                [
                    'category'      => null,
                    'base_price'    => $data['price'],
                    'base_duration' => $data['duration_minutes'],
                    'is_active'     => true,
                ]
            );

            // 3. master_service (Уровень 2) — идемпотентно по (master_id, catalog_id)
            //    override = NULL → наследует base из каталога
            MasterService::firstOrCreate(
                [
                    'master_id'  => $master->id,
                    'catalog_id' => $catalog->id,
                ],
                [
                    'price_override'    => null,
                    'duration_override' => null,
                    'is_custom'         => false,
                    'status'            => 'approved',
                    'is_active'         => true,
                ]
            );

            return $service;
        });
    }
}
