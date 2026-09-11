<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\VkLinkTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VkBookingLinkTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_id' => '54765769']);
    }

    private function createMaster(): User
    {
        $master = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $dayOfWeek = Carbon::tomorrow('Europe/Moscow')->dayOfWeek;

        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
                'is_working' => true,
            ],
        );

        return $master;
    }

    private function createService(User $master): MasterService
    {
        $workspace = Workspace::create(['name' => fake()->unique()->company(), 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Тестовая', 'base_price' => 1000.00, 'base_duration' => 60]);

        return MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'price_override' => 1000.00,
            'duration_override' => 60,
            'is_active' => true,
        ]);
    }

    private function bookSlot(User $master, MasterService $service, string $provider): \Illuminate\Testing\TestResponse
    {
        $tomorrow = Carbon::tomorrow('Europe/Moscow')->toDateString();

        return $this->postJson(route('booking.reserve', $master->master_slug), [
            'service_id' => $service->id,
            'date' => $tomorrow,
            'time' => '10:00',
            'provider' => $provider,
        ]);
    }

    public function test_provider_vk_passes_validation(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $response->assertOk();
    }

    public function test_provider_vk_creates_appointment_with_vk_provider(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $response->assertOk();
        $this->assertDatabaseHas('appointments', [
            'master_id' => $master->id,
            'provider' => 'vk',
        ]);
    }

    public function test_provider_vk_response_contains_vk_link_token(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $response->assertOk();
        $response->assertJsonStructure(['vk_link_token']);
        $this->assertNotNull($response->json('vk_link_token'));
    }

    public function test_vk_link_token_starts_with_link_vk_(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $this->assertStringStartsWith('link_vk_', $response->json('vk_link_token'));
    }

    public function test_vk_link_token_peeks_created_appointment(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');
        $token = $response->json('vk_link_token');
        $appointmentId = $response->json('appointment_id');

        $this->assertSame($appointmentId, app(VkLinkTokenService::class)->peek($token));
    }

    public function test_provider_max_does_not_create_vk_token(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'max');

        $response->assertOk();
        $this->assertNull($response->json('vk_link_token'));
    }

    public function test_provider_telegram_does_not_create_vk_token(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'telegram');

        $response->assertOk();
        $this->assertNull($response->json('vk_link_token'));
    }

    public function test_provider_vk_response_contains_vk_url(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $response->assertOk();
        $this->assertNotNull($response->json('vk_url'));
    }

    public function test_vk_url_starts_with_configured_app_id(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $this->assertStringStartsWith('https://vk.com/app54765769#', $response->json('vk_url'));
    }

    public function test_vk_url_fragment_equals_vk_link_token(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $token = $response->json('vk_link_token');
        $url = $response->json('vk_url');
        $this->assertSame($token, substr($url, strpos($url, '#') + 1));
    }

    public function test_vk_url_token_not_in_query_string(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $url = $response->json('vk_url');
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_provider_max_vk_url_is_null(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'max');

        $response->assertOk();
        $this->assertNull($response->json('vk_url'));
    }

    public function test_provider_telegram_vk_url_is_null(): void
    {
        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'telegram');

        $response->assertOk();
        $this->assertNull($response->json('vk_url'));
    }

    public function test_missing_vk_app_id_with_provider_vk_fails_closed(): void
    {
        config(['services.vk.app_id' => null]);

        $master = $this->createMaster();
        $service = $this->createService($master);

        $response = $this->bookSlot($master, $service, 'vk');

        $response->assertStatus(500);
    }
}
