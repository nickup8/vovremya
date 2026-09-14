<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Events\AppointmentStatusChanged;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class VkBookingConfirmTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppId = '6736218';
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';
    private User $master;
    private Workspace $ws;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['legal.version' => '11.08.2026']);
        config(['booking.draft_ttl' => 900]);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->ws = Workspace::create([
            'name' => 'WS Test',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                [
                    'is_working' => true,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                    'break_start_time' => null,
                    'break_end_time' => null,
                ],
            );
        }

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Стрижка',
            'base_price' => 1500,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    private function vkAuthHeaders(string $vkUserId): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);
        $sign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer ' . http_build_query($all)];
    }

    private function createToken(string $appointmentId): string
    {
        $token = 'link_vk_test_' . uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN . $token, $appointmentId, 900);

        return $token;
    }

    private function signPhone(string $userId, string $phone): string
    {
        $raw = hash('sha256', $this->testAppId . $this->testAppSecret . $userId . 'phone_number' . $phone, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function createDraftAppointment(?AppointmentStatus $status = null): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create([
                'status' => $status ?? AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);
    }

    private function createReturningClient(string $vkId = '494075', ?string $pdnVersion = '11.08.2026'): Client
    {
        return Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => $vkId,
            'pdn_consent_at' => $pdnVersion ? now() : null,
            'pdn_consent_version' => $pdnVersion,
        ]);
    }

    // ═══════════════════════════════════════
    // Status endpoint: returning client
    // ═══════════════════════════════════════

    public function test_status_same_master_current_consent_returns_phone_not_required(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson([
                'consent_required' => false,
                'phone_required' => false,
            ])
            ->assertJsonStructure(['appointment' => ['service', 'date', 'time', 'price']]);
    }

    public function test_status_same_master_stale_consent_returns_consent_required(): void
    {
        $client = $this->createReturningClient(pdnVersion: '01.01.2020');
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson([
                'consent_required' => true,
                'phone_required' => false,
            ]);
    }

    public function test_status_global_consent_from_another_master_with_stale_same_master(): void
    {
        $otherMaster = User::factory()->master()->create();

        // Current consent under other master
        Client::factory()->create([
            'user_id' => $otherMaster->id,
            'vk_id' => '494075',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '11.08.2026',
        ]);

        // Stale same-master client
        $client = $this->createReturningClient(pdnVersion: '01.01.2020');

        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson([
                'consent_required' => false,
                'phone_required' => false,
            ]);
    }

    public function test_status_vk_id_only_under_other_master_returns_phone_required(): void
    {
        $otherMaster = User::factory()->master()->create();

        Client::factory()->create([
            'user_id' => $otherMaster->id,
            'vk_id' => '494075',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '11.08.2026',
        ]);

        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson([
                'consent_required' => false,
                'phone_required' => true,
            ]);
    }

    // ═══════════════════════════════════════
    // Consent: saves PDN on same-master client
    // ═══════════════════════════════════════

    public function test_submit_consent_saves_pdn_on_same_master_client(): void
    {
        $client = $this->createReturningClient(pdnVersion: '01.01.2020');
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-consent', ['token' => $token])
            ->assertOk();

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('11.08.2026', $client->pdn_consent_version);
    }

    // ═══════════════════════════════════════
    // Confirm
    // ═══════════════════════════════════════

    public function test_confirm_assigns_client_source_vk_preserves_booked(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentSource::Vk, $appointment->source);
        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);

        Event::assertDispatched(AppointmentCreated::class);
    }

    public function test_confirm_preserves_pending_payment_status(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment(AppointmentStatus::PendingPayment);
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentStatus::PendingPayment, $appointment->status);
    }

    public function test_confirm_broadcasts_and_notifies_master(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        Event::assertDispatched(AppointmentCreated::class);
        $this->assertTrue(Cache::has('master_notified_' . $appointment->id));
    }

    public function test_confirm_consumes_token(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $this->assertNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    public function test_double_confirm_only_one_succeeds(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);

        // Second confirm fails — token consumed, appointment already claimed
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════
    // Cancel
    // ═══════════════════════════════════════

    public function test_cancel_transitions_to_cancelled(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentStatusChanged::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-cancel', ['token' => $token])
            ->assertOk();

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
        $this->assertNotNull($appointment->cancelled_at);

        Event::assertDispatched(AppointmentStatusChanged::class, function ($event) {
            return $event->oldStatus === AppointmentStatus::Booked
                && $event->newStatus === AppointmentStatus::Cancelled;
        });
    }

    public function test_cancel_pending_payment_preserves_previous_status_in_event(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment(AppointmentStatus::PendingPayment);
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentStatusChanged::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-cancel', ['token' => $token])
            ->assertOk();

        Event::assertDispatched(AppointmentStatusChanged::class, function ($event) {
            return $event->oldStatus === AppointmentStatus::PendingPayment
                && $event->newStatus === AppointmentStatus::Cancelled;
        });
    }

    public function test_cancel_consumes_token(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-cancel', ['token' => $token])
            ->assertOk();

        $this->assertNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    public function test_cancel_then_confirm_fails(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-cancel', ['token' => $token])
            ->assertOk();

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertStatus(422);

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
    }

    public function test_confirm_then_cancel_fails(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        // Token consumed, cancel should fail
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-cancel', ['token' => $token])
            ->assertStatus(422);

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);
    }

    // ═══════════════════════════════════════
    // Changed phone, same vk_id
    // ═══════════════════════════════════════

    public function test_fast_path_uses_existing_client_regardless_of_phone(): void
    {
        $client = $this->createReturningClient();
        $originalPhone = $client->phone;

        $appointment = $this->createDraftAppointment();

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $this->createToken($appointment->id))
            ->assertOk()
            ->assertJson(['phone_required' => false]);

        $client->refresh();
        $this->assertEquals($originalPhone, $client->phone);
    }

    // ═══════════════════════════════════════
    // Blocked returning client
    // ═══════════════════════════════════════

    public function test_blocked_returning_client_cannot_confirm(): void
    {
        $client = $this->createReturningClient();
        $client->update(['is_blocked' => true]);

        $appointmentId = $this->createDraftAppointment()->id;
        $token = $this->createToken($appointmentId);

        Event::fake([AppointmentCreated::class]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertStatus(403);

        // Appointment deleted for blocked client
        $this->assertDatabaseMissing('appointments', ['id' => $appointmentId]);
        Event::assertNotDispatched(AppointmentCreated::class);
    }

    // ═══════════════════════════════════════
    // Slow path: new client via link
    // ═══════════════════════════════════════

    public function test_slow_path_atomic_claim_and_token_consume(): void
    {
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Cache::put(CacheKeys::VK_CONSENT_PENDING . '494075', '11.08.2026', 900);

        Event::fake([AppointmentCreated::class]);

        $phone = '79001234567';
        $sign = $this->signPhone('494075', $phone);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $sign,
            ])
            ->assertOk();

        $appointment->refresh();
        $this->assertNotNull($appointment->client_id);
        $this->assertEquals(AppointmentSource::Vk, $appointment->source);

        Event::assertDispatched(AppointmentCreated::class);
        $this->assertNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    public function test_slow_path_blocked_client_rejected(): void
    {
        $appointmentId = $this->createDraftAppointment()->id;
        $token = $this->createToken($appointmentId);

        Cache::put(CacheKeys::VK_CONSENT_PENDING . '494075', '11.08.2026', 900);

        Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'is_blocked' => true,
        ]);

        $phone = '79001234567';
        $sign = $this->signPhone('494075', $phone);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $sign,
            ])
            ->assertStatus(403);

        // Appointment deleted for blocked client
        $this->assertDatabaseMissing('appointments', ['id' => $appointmentId]);
    }

    public function test_slow_path_claim_failure_does_not_link_vk_id(): void
    {
        $appointment = $this->createDraftAppointment();
        $token = $this->createToken($appointment->id);

        Cache::put(CacheKeys::VK_CONSENT_PENDING . '494075', '11.08.2026', 900);

        // Pre-claim the appointment
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);
        $appointment->update([
            'client_id' => $otherClient->id,
            'source' => AppointmentSource::Admin,
        ]);

        $phone = '79001234567';
        $sign = $this->signPhone('494075', $phone);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $sign,
            ])
            ->assertStatus(422);

        // Token should NOT be consumed
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));

        // No client should have this vk_id
        $this->assertNull(Client::where('vk_id', '494075')->first());
    }
}
