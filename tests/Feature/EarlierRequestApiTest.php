<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class EarlierRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;
    private string $testToken = 'test-bot-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.max.bot_token' => $this->testToken]);
        config(['booking.initdata_ttl' => 3600]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create();
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => '8039166',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(16, 0);

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => $localStart->copy()->setTimezone('UTC'),
                'duration' => 60,
            ]);
    }

    private function generateInitData(string $userId = '8039166'): string
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => (int) $userId, 'first_name' => 'Test']),
        ];

        ksort($params);

        $pairsForSign = [];
        foreach ($params as $key => $value) {
            $pairsForSign[] = $key.'='.$value;
        }
        $launchParams = implode("\n", $pairsForSign);
        $secretKey = hash_hmac('sha256', $this->testToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $launchParams, $secretKey, false);

        $pairsFinal = [];
        foreach ($params as $key => $value) {
            $pairsFinal[] = $key.'='.urlencode($value);
        }
        $pairsFinal[] = 'hash='.$hash;

        return implode('&', $pairsFinal);
    }

    private function maxHeaders(string $maxId = '8039166'): array
    {
        return ['X-Max-Init-Data' => $this->generateInitData($maxId)];
    }

    // ── Appointment list includes autofill fields ─────────

    public function test_appointment_list_includes_autofill_available(): void
    {
        $response = $this->getJson('/api/miniapp/appointments', $this->maxHeaders());

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $this->appointment->id,
            'autofill_available' => true,
            'earlier_request' => null,
        ]);
    }

    public function test_autofill_available_false_when_toggle_off(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $response = $this->getJson('/api/miniapp/appointments', $this->maxHeaders());

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $this->appointment->id,
            'autofill_available' => false,
        ]);
    }

    public function test_autofill_available_false_when_start_plan(): void
    {
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);

        $startPlan = TariffPlan::where('code', 'start')->first();
        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $response = $this->getJson('/api/miniapp/appointments', $this->maxHeaders());

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $this->appointment->id,
            'autofill_available' => false,
        ]);
    }

    // ── Create earlier request ────────────────────────────

    public function test_valid_create_earlier_request(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure(['earlier_request' => ['id', 'date_from', 'date_to', 'time_from', 'time_to', 'status']]);

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'client_id' => $this->client->id,
            'type' => 'earlier',
            'status' => 'active',
            'request_source' => 'max',
            'delivery_channel' => 'max',
        ]);
    }

    public function test_created_request_has_correct_source_and_channel(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertOk();

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'request_source' => 'max',
            'delivery_channel' => 'max',
        ]);
    }

    public function test_created_request_client_matches_appointment(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertOk();

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'client_id' => $this->client->id,
        ]);
    }

    // ── Update existing request ───────────────────────────

    public function test_update_preserves_id(): void
    {
        // Create first
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        $firstId = $this->appointment->fresh()->activeSlotRequest->id;

        // Update
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '10:00',
            'time_to' => '14:00',
        ], $this->maxHeaders());

        $response->assertOk();
        $this->assertEquals($firstId, $this->appointment->fresh()->activeSlotRequest->id);
    }

    // ── Full-day request ──────────────────────────────────

    public function test_full_day_request_persists(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '00:00:00',
            'time_to' => '23:59:59',
        ], $this->maxHeaders());

        $response->assertOk();

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'time_from' => '00:00:00',
            'time_to' => '23:59:59',
        ]);
    }

    // ── Validation ────────────────────────────────────────

    public function test_invalid_date_returns_422(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => 'invalid',
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertStatus(422);
    }

    public function test_invalid_time_returns_422(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '25:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertStatus(422);
    }

    // ── Non-Booked rejected ───────────────────────────────

    public function test_non_booked_appointment_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::Cancelled]);

        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertStatus(422);
    }

    // ── AutoFill disabled between GET and PUT ─────────────

    public function test_autofill_disabled_between_get_and_put(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'autofill_disabled');
    }

    // ── Downgrade Pro → Start ─────────────────────────────

    public function test_downgrade_rejects_update(): void
    {
        // Create request while Pro
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Downgrade
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);
        $startPlan = TariffPlan::where('code', 'start')->first();
        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        // Try to update
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '10:00',
            'time_to' => '14:00',
        ], $this->maxHeaders());

        $response->assertStatus(422);
    }

    public function test_active_request_still_returned_after_downgrade(): void
    {
        // Create request while Pro
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Downgrade
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);
        $startPlan = TariffPlan::where('code', 'start')->first();
        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        // List still shows the request
        $response = $this->getJson('/api/miniapp/appointments', $this->maxHeaders());
        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $this->appointment->id,
            'autofill_available' => false,
        ]);
        $response->assertJsonPath('0.earlier_request.id', fn ($id) => $id !== null);
    }

    // ── Cancel ────────────────────────────────────────────

    public function test_cancel_active_request(): void
    {
        // Create
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Cancel
        $response = $this->deleteJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [], $this->maxHeaders());
        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_works_after_downgrade(): void
    {
        // Create while Pro
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Downgrade
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);
        $startPlan = TariffPlan::where('code', 'start')->first();
        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        // Cancel still works
        $response = $this->deleteJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [], $this->maxHeaders());
        $response->assertOk();
    }

    public function test_cancel_works_when_autofill_disabled(): void
    {
        // Create
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Disable autofill
        $this->master->update(['autofill_enabled' => false]);

        // Cancel still works
        $response = $this->deleteJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [], $this->maxHeaders());
        $response->assertOk();
    }

    public function test_cancel_idempotent_when_no_request(): void
    {
        $response = $this->deleteJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [], $this->maxHeaders());
        $response->assertOk();
    }

    // ── Security ──────────────────────────────────────────

    public function test_other_max_client_cannot_create(): void
    {
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders('max_other_user'));

        $response->assertStatus(403);
    }

    public function test_other_max_client_cannot_cancel(): void
    {
        // Create with correct user
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Try to cancel with different user
        $response = $this->deleteJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [], $this->maxHeaders('max_other_user'));
        $response->assertStatus(403);
    }

    // ── Multi-master client resolution ────────────────────

    public function test_multi_master_client_resolution(): void
    {
        // Create a second workspace/master with the same MAX user
        $master2 = User::factory()->master()->create();
        $ws2 = Workspace::create(['name' => 'WS2', 'owner_id' => $master2->id]);
        $master2->update(['workspace_id' => $ws2->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $ws2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        $client2 = Client::factory()->create([
            'user_id' => $master2->id,
            'workspace_id' => $ws2->id,
            'max_id' => '8039166', // same MAX user
        ]);

        $catalog2 = ServiceCatalog::create([
            'workspace_id' => $ws2->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 30,
        ]);
        $ms2 = MasterService::create(['master_id' => $master2->id, 'catalog_id' => $catalog2->id, 'is_active' => true]);

        $appt2 = Appointment::factory()
            ->forMaster($master2)->forClient($client2)->withMasterService($ms2)
            ->create(['status' => AppointmentStatus::Booked, 'start_time' => Carbon::tomorrow()->setTime(14, 0), 'duration' => 30]);

        // Create request for appointment 1 — should use client1, not client2
        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders());

        $response->assertOk();

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'client_id' => $this->client->id, // correct client, not client2
        ]);
    }

    // ── N+1 prevention ────────────────────────────────────

    public function test_appointments_list_eager_loads_request(): void
    {
        // Create a request
        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->maxHeaders())->assertOk();

        // Verify the response includes the eager-loaded request
        $response = $this->getJson('/api/miniapp/appointments', $this->maxHeaders());
        $response->assertOk();
        $response->assertJsonPath('0.earlier_request.id', fn ($id) => $id !== null);
    }
}
