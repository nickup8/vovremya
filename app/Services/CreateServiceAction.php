<?php

namespace App\Services;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateServiceAction
{
    /**
     * Создаёт услугу мастера: service_catalog (справочник workspace) + master_service (назначение).
     * Legacy services БОЛЬШЕ НЕ ПИШЕТСЯ. Единое ID-пространство — master_service.
     *
     * @param  array{title:string, price:float|string, duration_minutes:int}  $data
     */
    public function execute(User $master, array $data): MasterService
    {
        if (! $master->workspace_id) {
            throw new \RuntimeException("Master {$master->id}: workspace_id missing");
        }

        return DB::transaction(function () use ($master, $data) {
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

            return MasterService::firstOrCreate(
                [
                    'master_id'  => $master->id,
                    'catalog_id' => $catalog->id,
                ],
                [
                    'price_override'    => null,
                    'duration_override' => null,
                    'is_active'         => true,
                ]
            );
        });
    }
}
