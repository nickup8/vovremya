<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'phone' => null,
            'telegram_id' => null,
            'telegram_chat_id' => null,
            'telegram_auth_token' => null,
            'max_id' => null,
            'avatar_url' => null,
            'is_master' => false,
            'is_super_admin' => false,
            'is_blocked' => false,
            'master_slug' => null,
            'specialty' => null,
            'address' => null,
            'telegram_notifications' => false,
            'max_notifications' => false,
            'soft_deposit' => false,
            'deposit_timeout' => 15,
            'deposit_percent' => 30,
            'slot_interval' => 30,
            'tariff' => 'free',
            'expires_at' => null,
            'settings' => null,
        ];
    }

    public function master(): static
    {
        return $this->state(fn () => [
            'is_master' => true,
            'master_slug' => Str::slug(fake()->unique()->userName()),
            'specialty' => fake()->randomElement(['Маникюр & Педикюр', 'Парикмахер', 'Барбер', 'Косметолог']),
            'address' => fake()->address(),
            'phone' => fake()->unique()->phoneNumber(),
            'telegram_notifications' => true,
            'max_notifications' => true,
            'soft_deposit' => true,
            'deposit_timeout' => 15,
            'deposit_percent' => 20,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
