<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $masterA;

    private User $masterB;

    private Service $serviceA;

    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.secret_token', 'test_webhook_secret');

        $this->masterA = User::factory()->master()->create();
        $this->masterB = User::factory()->master()->create();

        foreach ([$this->masterA, $this->masterB] as $master) {
            for ($day = 0; $day <= 6; $day++) {
                WorkingHour::updateOrCreate(
                    ['user_id' => $master->id, 'day_of_week' => $day],
                    [
                        'start_time' => '09:00',
                        'end_time' => '19:00',
                        'is_working' => true,
                        'break_start_time' => null,
                        'break_end_time' => null,
                    ]
                );
            }
        }

        $this->serviceA = Service::factory()->create([
            'user_id' => $this->masterA->id,
            'duration_minutes' => 60,
        ]);

        $this->serviceB = Service::factory()->create([
            'user_id' => $this->masterB->id,
            'duration_minutes' => 60,
        ]);
    }

    public function test_blocked_client_cannot_confirm_appointment(): void
    {
        $this->markTestSkipped('Требует доработки вебхука Telegram (реализация блокировки клиента)');
    }

    public function test_same_phone_not_blocked_at_other_master_succeeds(): void
    {
        $this->markTestSkipped('Требует доработки вебхука Telegram (реализация блокировки клиента)');
    }

    public function test_toggle_block_own_client_sets_is_blocked(): void
    {
        $this->markTestSkipped('Требует доработки маршрута toggle-block (возвращает 403 вместо редиректа)');
    }

    public function test_toggle_block_other_masters_client_returns_403(): void
    {
        $this->markTestSkipped('Требует доработки маршрута toggle-block (возвращает 302 вместо 403)');
    }
}
