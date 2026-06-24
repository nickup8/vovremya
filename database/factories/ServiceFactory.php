<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement([
                'Маникюр с покрытием',
                'Педикюр',
                'Снятие + выравнивание',
                'Дизайн ногтей',
            ]),
            'price' => fake()->randomFloat(2, 500, 2500),
            'duration_minutes' => fake()->randomElement([30, 60, 90, 120]),
        ];
    }
}
