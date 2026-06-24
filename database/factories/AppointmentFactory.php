<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $service = Service::inRandomOrder()->first() ?? Service::factory()->create();

        $start = fake()->dateTimeBetween('-1 week', '+1 week');
        $start->setTime(
            fake()->numberBetween(8, 19),
            fake()->randomElement([0, 15, 30, 45])
        );

        return [
            'master_id' => User::factory()->master(),
            'client_id' => User::factory(),
            'service_id' => $service->id,
            'start_time' => $start,
            'status' => fake()->randomElement(['confirmed', 'pending_client']),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => 'confirmed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending_client',
        ]);
    }
}
