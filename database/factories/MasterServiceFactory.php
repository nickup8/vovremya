<?php

namespace Database\Factories;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MasterService>
 */
class MasterServiceFactory extends Factory
{
    protected $model = MasterService::class;

    public function definition(): array
    {
        return [
            'master_id' => User::factory()->master(),
            'catalog_id' => ServiceCatalog::factory(),
            'is_active' => true,
            'status' => 'approved',
            'is_custom' => false,
        ];
    }

    /**
     * Создать консистентный набор: master = owner workspace → catalog → master_service.
     * Паттерн из BookingWidgetAccessTest.
     */
    public function forMaster(User $master): static
    {
        $workspace = Workspace::create(['name' => fake()->unique()->company(), 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => fake()->unique()->words(2, true),
            'base_price' => fake()->randomFloat(2, 500, 2500),
            'base_duration' => fake()->randomElement([30, 60, 90, 120]),
        ]);

        return $this->state(fn () => [
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);
    }
}
