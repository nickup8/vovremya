<?php

namespace Tests\Feature\Breaking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\Client\ClientMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityAndLogicBreakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тест 2.1: IDOR — Master B пытается изменить запись Master A.
     *
     * BUG: CalendarController::updateStatus не проверяет,
     * что $appointment принадлежит текущему auth()->user().
     * Любой авторизованный мастер может менять статус ЧУЖИХ записей.
     */
    public function test_master_cannot_update_other_masters_appointment(): void
    {
        $masterA = User::factory()->master()->create();
        $masterB = User::factory()->master()->create();

        $serviceA = Service::factory()->for($masterA)->create();
        $client = Client::factory()->for($masterA)->create();

        $appointment = Appointment::factory()
            ->forMaster($masterA)
            ->forClient($client)
            ->withService($serviceA)
            ->confirmed()
            ->create();

        $this->actingAs($masterB);

        $response = $this->patch(route('admin.appointments.update-status', $appointment->id), [
            'status' => AppointmentStatus::Cancelled->value,
        ]);

        // Если код НЕ проверяет ownership, вернёт 302 (redirect back без ошибки).
        // Ожидаем 403/404 — но текущий код вернёт 302, что и подтверждает баг.
        $response->assertStatus(403);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => AppointmentStatus::Confirmed->value,
        ]);
    }

    /**
     * Тест 2.2: N+1 — CalendarController загружает все записи без eager loading.
     *
     * BUG: CalendarController::index() делает $master->masterAppointments()->get()
     * и потом обращается к $a->client->name и $a->service->title внутри map().
     * Для 100 записей это 100+ запросов вместо 3-5.
     */
    public function test_calendar_controller_does_not_generate_n_plus_one_queries(): void
    {
        $master = User::factory()->master()->create();
        $service = Service::factory()->for($master)->create();

        for ($i = 0; $i < 20; $i++) {
            $client = Client::factory()->for($master)->create();
            Appointment::factory()
                ->forMaster($master)
                ->forClient($client)
                ->withService($service)
                ->confirmed()
                ->create();
        }

        $this->actingAs($master);

        $queriesBefore = DB::getQueryLog();
        $queryCount = 0;

        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->get(route('admin.calendar'));

        $response->assertOk();

        // Если контроллер загружает все записи и обращается к relationship
        // внутри map() без eager loading — запросов будет >> 10.
        // Текущий код: masterAppointments()->with(['client', 'service'])->get()
        // Должно укладываться в 5-8 запросов.
        // Если упадет — значит eager loading НЕ работает или есть лишние запросы.
        $this->assertLessThanOrEqual(10, $queryCount, "Calendar generated {$queryCount} queries — N+1 detected");
    }

    /**
     * Тест 2.3: Конечный автомат — отменённую запись нельзя вернуть в confirmed.
     *
     * BUG: CalendarController::updateStatus не валидирует переходы состояний.
     * Запись в статусе cancelled можно перевести обратно в confirmed.
     * Это нарушает бизнес-логику: отменённая запись не должна восстанавливаться.
     */
    public function test_cancelled_appointment_cannot_be_reactivated(): void
    {
        $master = User::factory()->master()->create();
        $service = Service::factory()->for($master)->create();
        $client = Client::factory()->for($master)->create();

        $appointment = Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withService($service)
            ->cancelled()
            ->create();

        $this->actingAs($master);

        $response = $this->patchJson(route('admin.appointments.update-status', $appointment->id), [
            'status' => AppointmentStatus::Confirmed->value,
        ]);

        // Если код НЕ проверяет допустимые переходы состояний — вернёт 302 (redirect back).
        // Ожидаем 422 или 400 — но текущий код вернёт 302, что и подтверждает баг.
        $response->assertStatus(422);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => AppointmentStatus::Cancelled->value,
        ]);
    }

    /**
     * Тест 2.4: UPSERT — склейка профилей клиентов через разные провайдеры.
     *
     * BUG: ClientMergeService::findOrCreateByPhone() использует updateOrCreate
     * по (user_id, phone), но НЕ обновляет telegram_id/max_id если они уже есть.
     * При webhook от Telegram создаётся клиент с telegram_id.
     * При webhook от Max с тем же телефоном — max_id перезапишет telegram_id.
     */
    public function test_upsert_preserves_both_provider_ids(): void
    {
        $master = User::factory()->master()->create();

        $mergeService = app(ClientMergeService::class);

        $client = $mergeService->findOrCreateByPhone($master->id, '79990000000', 'tg_123456', 'Клиент Тест');

        $finalClient = Client::where('user_id', $master->id)
            ->where('phone', '79990000000')
            ->first();

        $this->assertNotNull($finalClient->telegram_id, 'telegram_id should be set after first call');

        $mergeService->linkProvider($finalClient, 'max', 'max_789012');

        $finalClient->refresh();

        $this->assertNotNull($finalClient->telegram_id, 'telegram_id should be preserved after Max link');
        $this->assertNotNull($finalClient->max_id, 'max_id should be set after Max link');
        $this->assertEquals('tg_123456', $finalClient->telegram_id);
        $this->assertEquals('max_789012', $finalClient->max_id);
    }

    /**
     * Бонус: Webhook без верификации подписи.
     *
     * BUG: WebhookController::handleTelegram не проверяет
     * HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN.
     */
    public function test_webhook_has_secret_token_verification(): void
    {
        config(['services.telegram.secret_token' => 'test-secret-abc']);

        $payload = [
            'message' => [
                'chat' => ['id' => 123456],
                'text' => '/start book_999',
            ],
        ];

        $response = $this->postJson(route('webhooks.telegram'), $payload);

        $response->assertStatus(403);
    }

    /**
     * Бонус: Dev-роут /dev/login-master защищён от production.
     */
    public function test_dev_routes_return_404_in_production(): void
    {
        $master = User::factory()->master()->create();

        $this->app['env'] = 'production';

        $response = $this->get("/dev/impersonate/{$master->id}");
        $response->assertStatus(404);
    }
}
