<?php

namespace Tests\Feature\MiniApp;

use App\Enums\AppointmentStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotRequest;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VkMiniAppApiTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';
    private string $testAppId = '6736218';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['services.vk.app_id' => $this->testAppId]);
    }

    private function sign(array $vkParams): string
    {
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);

        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
    }

    private function vkAuthHeaders(string $vkUserId): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        $sign = $this->sign($vkParams);
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer ' . http_build_query($all)];
    }

    private function createWorkspaceWithPro(): Workspace
    {
        $proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return $ws->load('owner');
    }

    // ═══════════════════════════════════════════
    // 1. VK → APPOINTMENTS (multi-master)
    // ═══════════════════════════════════════════

    public function test_vk_appointments_returns_records_from_both_masters(): void
    {
        $vkId = '494075';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $client1 = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master1->id]);
        $client2 = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master2->id]);

        $ms1 = MasterService::factory()->forMaster($master1)->create();
        $ms2 = MasterService::factory()->forMaster($master2)->create();

        $appt1 = Appointment::factory()
            ->forMaster($master1)->forClient($client1)->withMasterService($ms1)
            ->create(['status' => AppointmentStatus::Booked, 'start_time' => now()->addDay()]);

        $appt2 = Appointment::factory()
            ->forMaster($master2)->forClient($client2)->withMasterService($ms2)
            ->create(['status' => AppointmentStatus::Booked, 'start_time' => now()->addDays(2)]);

        $response = $this->withHeaders($this->vkAuthHeaders($vkId))
            ->getJson('/api/miniapp/appointments');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($appt1->id));
        $this->assertTrue($ids->contains($appt2->id));
    }

    // ═══════════════════════════════════════════
    // 2. VK → PROFILE
    // ═══════════════════════════════════════════

    public function test_vk_profile_returns_linked_client_data(): void
    {
        $vkId = '494075';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'vk_id' => $vkId,
            'user_id' => $master->id,
            'name' => 'Тест Тестов',
            'phone' => '79001234567',
        ]);

        $response = $this->withHeaders($this->vkAuthHeaders($vkId))
            ->getJson('/api/miniapp/profile');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Тест Тестов', 'phone' => '79001234567']);
    }

    // ═══════════════════════════════════════════
    // 3. VK → CANCEL
    // ═══════════════════════════════════════════

    public function test_vk_cancel_own_appointment(): void
    {
        $vkId = '494075';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master->id]);
        $ms = MasterService::factory()->forMaster($master)->create();

        $appointment = Appointment::factory()
            ->forMaster($master)->forClient($client)->withMasterService($ms)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => now()->addDays(3),
            ]);

        $response = $this->withHeaders($this->vkAuthHeaders($vkId))
            ->postJson("/api/miniapp/appointments/{$appointment->id}/cancel");

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    // ═══════════════════════════════════════════
    // 4. VK → DELETE EARLIER REQUEST
    // ═══════════════════════════════════════════

    public function test_vk_delete_earlier_request(): void
    {
        $ws = $this->createWorkspaceWithPro();
        $master = $ws->owner;
        $vkId = '494075';

        $client = Client::factory()->create([
            'vk_id' => $vkId,
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Массаж', 'base_price' => 2000, 'base_duration' => 60,
        ]);
        $ms = MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        $tz = $master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(16, 0);

        $appointment = Appointment::factory()
            ->forMaster($master)->forClient($client)->withMasterService($ms)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => $localStart->copy()->setTimezone('UTC'),
                'duration' => 60,
            ]);

        // Create an active slot request
        SlotRequest::create([
            'workspace_id' => $ws->id,
            'master_id' => $master->id,
            'client_id' => $client->id,
            'appointment_id' => $appointment->id,
            'master_service_id' => $ms->id,
            'type' => SlotRequestType::Earlier,
            'status' => SlotRequestStatus::Active,
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'timezone' => $master->getTimezone(),
            'appointment_start_time_snapshot' => $localStart->copy()->setTimezone('UTC'),
            'date_from' => now()->toDateString(),
            'date_to' => $localStart->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
        ]);

        $response = $this->withHeaders($this->vkAuthHeaders($vkId))
            ->deleteJson("/api/miniapp/appointments/{$appointment->id}/earlier-request");

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    // ═══════════════════════════════════════════
    // 5. VK → PUT EARLIER REQUEST → 401
    // ═══════════════════════════════════════════

    public function test_vk_put_earlier_request_rejected(): void
    {
        $master = User::factory()->master()->create();
        $vkId = '494075';
        $client = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master->id]);
        $ms = MasterService::factory()->forMaster($master)->create();

        $appointment = Appointment::factory()
            ->forMaster($master)->forClient($client)->withMasterService($ms)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => now()->addDays(3),
            ]);

        $response = $this->withHeaders($this->vkAuthHeaders($vkId))
            ->putJson("/api/miniapp/appointments/{$appointment->id}/earlier-request", [
                'date_from' => now()->toDateString(),
                'date_to' => now()->addDays(7)->toDateString(),
                'time_from' => '09:00',
                'time_to' => '18:00',
            ]);

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 6. VK → PING → 401
    // ═══════════════════════════════════════════

    public function test_vk_ping_rejected(): void
    {
        $response = $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/ping');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 7. INVALID VK → 401
    // ═══════════════════════════════════════════

    public function test_invalid_vk_returns_401_on_safe_endpoint(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer vk_user_id=12345&vk_app_id=' . $this->testAppId . '&sign=bad',
        ])->getJson('/api/miniapp/appointments');

        $response->assertStatus(401);
    }
}
