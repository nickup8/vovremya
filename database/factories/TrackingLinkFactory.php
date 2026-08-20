<?php

namespace Database\Factories;

use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrackingLink>
 */
class TrackingLinkFactory extends Factory
{
    protected $model = TrackingLink::class;

    public function definition(): array
    {
        return [
            'master_id' => User::factory()->master(),
            'name' => fake()->randomElement(['Instagram', 'VK август', '2GIS', 'Блогер Катя']),
            'token' => Str::lower(Str::random(16)),
            'is_active' => true,
        ];
    }

    public function forMaster(User $master): static
    {
        return $this->state(fn () => ['master_id' => $master->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
