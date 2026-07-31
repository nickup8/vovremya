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

            // 2+3. catalog + master (вынесено в syncCatalogAndMaster)
            $this->syncCatalogAndMaster($service);

            return $service;
        });
    }

    /**
     * Идемпотентно создаёт catalog (Уровень 1) + master_service (Уровень 2) из legacy Service.
     * Переиспользуется C4 (после создания legacy) и C5 (backfill существующих).
     * НЕ создаёт legacy Service — работает с уже существующим.
     */
    public function syncCatalogAndMaster(Service $service): void
    {
        $master = $service->master;

        if (! $master || ! $master->workspace_id) {
            throw new \RuntimeException("Service {$service->id}: master or workspace_id missing");
        }

        $catalog = ServiceCatalog::firstOrCreate(
            [
                'workspace_id' => $master->workspace_id,
                'title'        => $service->title,
            ],
            [
                'category'      => null,
                'base_price'    => $service->price,
                'base_duration' => $service->duration_minutes,
                'is_active'     => true,
            ]
        );

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
    }
}
