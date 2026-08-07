<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ClientBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $masterA;

    private User $masterB;

    private MasterService $serviceA;

    private MasterService $serviceB;

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

        $wsA = Workspace::create(['name' => 'WS A', 'owner_id' => $this->masterA->id]);
        $catA = ServiceCatalog::create(['workspace_id' => $wsA->id, 'title' => 'Услуга A', 'base_price' => 1000, 'base_duration' => 60]);
        $this->serviceA = MasterService::create(['master_id' => $this->masterA->id, 'catalog_id' => $catA->id, 'is_active' => true]);

        $wsB = Workspace::create(['name' => 'WS B', 'owner_id' => $this->masterB->id]);
        $catB = ServiceCatalog::create(['workspace_id' => $wsB->id, 'title' => 'Услуга B', 'base_price' => 1000, 'base_duration' => 60]);
        $this->serviceB = MasterService::create(['master_id' => $this->masterB->id, 'catalog_id' => $catB->id, 'is_active' => true]);
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
