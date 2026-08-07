<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
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
        $start = fake()->dateTimeBetween('-1 week', '+1 week');
        $start->setTime(
            fake()->numberBetween(8, 19),
            fake()->randomElement([0, 15, 30, 45])
        );

        return [
            'master_id' => User::factory()->master()->create()->id,
            'client_id' => null,
            'service_id' => null,
            'service_name' => fake()->randomElement(['Стрижка', 'Маникюр', 'Окрашивание', 'Педикюр']),
            'price' => fake()->randomElement([1000, 1500, 2000, 2500, 3000]),
            'duration' => fake()->randomElement([30, 60, 90, 120]),
            'start_time' => $start,
            'status' => fake()->randomElement(['booked']),
        ];
    }

    public function booked(): static
    {
        return $this->state(fn () => [
            'status' => 'booked',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'status' => 'no_show',
        ]);
    }

    public function forMaster(User $master): static
    {
        return $this->state(fn () => [
            'master_id' => $master->id,
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'client_id' => $client->id,
        ]);
    }

    public function withMasterService(MasterService $masterService): static
    {
        return $this->state(fn () => [
            'master_service_id' => $masterService->id,
        ]);
    }

    public function provider(string $provider): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
        ]);
    }
}
