<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        if ($user->is_master) {
            $this->createDefaultWorkingHours($user);
        }
    }

    private function createDefaultWorkingHours(User $user): void
    {
        $defaults = [
            0 => ['is_working' => false, 'start_time' => null, 'end_time' => null, 'break_start_time' => null, 'break_end_time' => null],
            1 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            2 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            3 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            4 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            5 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            6 => ['is_working' => true, 'start_time' => '10:00', 'end_time' => '15:00', 'break_start_time' => null, 'break_end_time' => null],
        ];

        foreach ($defaults as $dayOfWeek => $data) {
            $user->workingHours()->create([
                'day_of_week' => $dayOfWeek,
                'is_working' => $data['is_working'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'break_start_time' => $data['break_start_time'],
                'break_end_time' => $data['break_end_time'],
            ]);
        }
    }
}
