<?php

namespace Database\Factories;

use App\Models\BlockedTime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedTime>
 */
class BlockedTimeFactory extends Factory
{
    protected $model = BlockedTime::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+1 week');
        $start->setTime(10, 0);
        $end = (clone $start)->modify('+2 hours');

        return [
            'user_id' => User::factory(),
            'start_datetime' => $start,
            'end_datetime' => $end,
            'reason' => 'Другое',
        ];
    }

    public function forMaster(User $master): static
    {
        return $this->state(fn () => [
            'user_id' => $master->id,
        ]);
    }

    public function between(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): static
    {
        return $this->state(fn () => [
            'start_datetime' => $start,
            'end_datetime' => $end,
        ]);
    }

    public function reason(string $reason): static
    {
        return $this->state(fn () => [
            'reason' => $reason,
        ]);
    }
}
