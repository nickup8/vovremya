<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingHour>
 */
class WorkingHourFactory extends Factory
{
    protected $model = WorkingHour::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_start_time' => '13:00',
            'break_end_time' => '14:00',
            'is_working' => true,
        ];
    }

    public function forMaster(User $master): static
    {
        return $this->state(fn () => [
            'user_id' => $master->id,
        ]);
    }

    public function day(int $dayOfWeek): static
    {
        return $this->state(fn () => [
            'day_of_week' => $dayOfWeek,
        ]);
    }

    public function hours(string $start, string $end): static
    {
        return $this->state(fn () => [
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function breakHours(?string $start, ?string $end): static
    {
        return $this->state(fn () => [
            'break_start_time' => $start,
            'break_end_time' => $end,
        ]);
    }

    public function dayOff(): static
    {
        return $this->state(fn () => [
            'is_working' => false,
            'start_time' => null,
            'end_time' => null,
            'break_start_time' => null,
            'break_end_time' => null,
        ]);
    }
}
