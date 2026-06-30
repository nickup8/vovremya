<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('+7900#######'),
            'telegram_id' => null,
            'max_id' => null,
            'auth_token' => null,
            'is_blocked' => false,
        ];
    }

    public function withTelegram(): static
    {
        return $this->state(fn () => [
            'telegram_id' => 'tg_'.fake()->unique()->numerify('######'),
        ]);
    }

    public function withMax(): static
    {
        return $this->state(fn () => [
            'max_id' => 'max_'.fake()->unique()->numerify('######'),
        ]);
    }
}
