<?php

namespace Database\Factories;

use App\Models\ServiceCatalog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCatalog>
 */
class ServiceCatalogFactory extends Factory
{
    protected $model = ServiceCatalog::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::create(['name' => fake()->unique()->company(), 'owner_id' => \App\Models\User::factory()->create()->id])->id,
            'title' => fake()->unique()->words(2, true),
            'base_price' => fake()->randomFloat(2, 500, 2500),
            'base_duration' => fake()->randomElement([30, 60, 90, 120]),
            'is_active' => true,
        ];
    }
}
