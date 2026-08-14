<?php

namespace Tests\Feature\MiniApp;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\AppointmentStatusService;
use App\Services\Booking\BookingService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    private string $testToken = 'test-bot-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.max.bot_token' => $this->testToken]);
        config(['booking.initdata_ttl' => 3600]);
    }

    // ═══════════════════════════════════════════
    // ХЕЛПЕРЫ
    // ═══════════════════════════════════════════

    /**
     * Генерирует валидную initData строку (из MaxInitDataVerifierTest).
     */
    private function generateInitData(string $userId = '8039166', array $extraParams = []): string
    {
        $params = array_merge([
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => (int) $userId, 'first_name' => 'Test']),
        ], $extraParams);

        $params = array_filter($params, fn ($v) => $v !== null);
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

    private function authHeaders(string $userId = '8039166'): array
    {
        return ['X-Max-Init-Data' => $this->generateInitData($userId)];
    }

    // ═══════════════════════════════════════════
    // INDEX — АКТИВНЫЕ ЗАПИСИ
    // ═══════════════════════════════════════════

    public function test_index_returns_appointments_from_both_masters(): void
    {
        $maxId = '111222333';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        // клиент с 2 строками у разных мастеров
        $client1 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master1->id]);
        $client2 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master2->id]);

        $a1 = Appointment::factory()->booked()->forMaster($master1)->forClient($client1)->create([
            'start_time' => now()->addDay(),
        ]);
        $a2 = Appointment::factory()->booked()->forMaster($master2)->forClient($client2)->create([
            'start_time' => now()->addDays(2),
        ]);

        $response = $this->getJson('/api/miniapp/appointments', $this->authHeaders($maxId));

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(2, $data);

        $ids = array_column($data, 'id');
        $this->assertContains($a1->id, $ids);
        $this->assertContains($a2->id, $ids);
    }

    public function test_index_excludes_other_clients_appointments(): void
    {
        $maxId = '111222333';
        $otherMaxId = '999888777';
        $master = User::factory()->master()->create();

        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);
        $otherClient = Client::factory()->create(['max_id' => $otherMaxId, 'user_id' => $master->id]);

        $mine = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addDay()->setTime(10, 0),
        ]);
        Appointment::factory()->booked()->forMaster($master)->forClient($otherClient)->create([
            'start_time' => now()->addDay()->setTime(12, 0),
        ]);

        $response = $this->getJson('/api/miniapp/appointments', $this->authHeaders($maxId));

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame($mine->id, $response->json()[0]['id']);
    }

    public function test_index_empty_when_no_clients(): void
    {
        $response = $this->getJson('/api/miniapp/appointments', $this->authHeaders('000000'));

        $response->assertOk();
        $this->assertEmpty($response->json());
    }

    // ═══════════════════════════════════════════
    // HISTORY — ИСТОРИЯ
    // ═══════════════════════════════════════════

    public function test_history_returns_past_appointments_including_cancelled(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        $pastBooked = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->subDays(3)->setTime(10, 0),
        ]);
        $pastCancelled = Appointment::factory()->cancelled()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->subDays(1)->setTime(14, 0),
        ]);
        // будущее — не должно попасть
        Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addDay()->setTime(10, 0),
        ]);

        $response = $this->getJson('/api/miniapp/appointments/history', $this->authHeaders($maxId));

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(2, $data);

        $ids = array_column($data, 'id');
        $this->assertContains($pastBooked->id, $ids);
        $this->assertContains($pastCancelled->id, $ids);
    }

    // ═══════════════════════════════════════════
    // CANCEL — ОТМЕНА
    // ═══════════════════════════════════════════

    public function test_cancel_success(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addDays(3),
        ]);

        $this->mock(MasterNotificationService::class, function ($mock) {
            $mock->shouldReceive('sendToMaster')->once();
        });

        $response = $this->postJson(
            "/api/miniapp/appointments/{$appointment->id}/cancel",
            [],
            $this->authHeaders($maxId)
        );

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_forbidden_for_other_client(): void
    {
        $maxId = '111222333';
        $otherMaxId = '999888777';
        $master = User::factory()->master()->create();
        $otherClient = Client::factory()->create(['max_id' => $otherMaxId, 'user_id' => $master->id]);

        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($otherClient)->create([
            'start_time' => now()->addDays(3),
        ]);

        $response = $this->postJson(
            "/api/miniapp/appointments/{$appointment->id}/cancel",
            [],
            $this->authHeaders($maxId)
        );

        $response->assertStatus(403)->assertJson(['error' => 'forbidden']);
    }

    public function test_cancel_deadline_passed(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => 48]);
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        // запись через 24 часа — дедлайн 48ч уже прошёл
        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addHours(24),
        ]);

        $response = $this->postJson(
            "/api/miniapp/appointments/{$appointment->id}/cancel",
            [],
            $this->authHeaders($maxId)
        );

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertSame('deadline_passed', $data['error']);
        $this->assertSame(48, $data['deadline_hours']);
    }

    public function test_cancel_not_booked_or_past(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        // прошлая запись
        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->subDay(),
        ]);

        $response = $this->postJson(
            "/api/miniapp/appointments/{$appointment->id}/cancel",
            [],
            $this->authHeaders($maxId)
        );

        $response->assertStatus(422);
        $this->assertSame('not_cancellable', $response->json('error'));
    }

    public function test_cancel_succeeds_even_if_master_notification_fails(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addDays(3),
        ]);

        // мок: уведомление бросает исключение
        $this->mock(MasterNotificationService::class, function ($mock) {
            $mock->shouldReceive('sendToMaster')->andThrow(new \RuntimeException('bot down'));
        });

        $response = $this->postJson(
            "/api/miniapp/appointments/{$appointment->id}/cancel",
            [],
            $this->authHeaders($maxId)
        );

        // отмена прошла, несмотря на падение уведомления
        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'cancelled',
        ]);
    }

    // ═══════════════════════════════════════════
    // PROFILE — ПРОФИЛЬ
    // ═══════════════════════════════════════════

    public function test_profile_returns_name_and_phone(): void
    {
        $maxId = '111222333';
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'max_id' => $maxId,
            'user_id' => $master->id,
            'name' => 'Иван Иванов',
            'phone' => '+79001234567',
        ]);

        $response = $this->getJson('/api/miniapp/profile', $this->authHeaders($maxId));

        $response->assertOk()->assertJson([
            'name' => 'Иван Иванов',
            'phone' => '+79001234567',
        ]);
    }

    public function test_profile_no_client_rows(): void
    {
        $response = $this->getJson('/api/miniapp/profile', $this->authHeaders('000000'));

        $response->assertOk()->assertJson([
            'name' => null,
            'phone' => null,
        ]);
    }

    // ═══════════════════════════════════════════
    // AUTH — 401 БЕЗ ВАЛИДНЫХ ДАННЫХ
    // ═══════════════════════════════════════════

    public function test_index_returns_401_without_init_data(): void
    {
        $this->getJson('/api/miniapp/appointments')->assertStatus(401);
    }

    public function test_history_returns_401_without_init_data(): void
    {
        $this->getJson('/api/miniapp/appointments/history')->assertStatus(401);
    }

    public function test_cancel_returns_401_without_init_data(): void
    {
        // Route model binding вернёт 404 для несуществующего UUID до проверки middleware — это нормально.
        // Проверяем auth на существующем UUID (без initData → 401 от middleware).
        // Для этого создаём запись, но шлём запрос без initData — middleware сработает первым,
        // т.к. route model binding резолвится ДО middleware только если UUID невалиден.
        // С валидным UUID route пройдёт, и middleware вернёт 401.
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $client = Client::factory()->create(['max_id' => '999', 'user_id' => $master->id]);
        $appointment = Appointment::factory()->booked()->forMaster($master)->forClient($client)->create([
            'start_time' => now()->addDays(5),
        ]);

        $this->postJson("/api/miniapp/appointments/{$appointment->id}/cancel")->assertStatus(401);
    }

    public function test_profile_returns_401_without_init_data(): void
    {
        $this->getJson('/api/miniapp/profile')->assertStatus(401);
    }
}
