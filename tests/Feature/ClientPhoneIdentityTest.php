<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\DuplicateClientPhoneException;
use App\Exceptions\ProviderLinkConflictException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Client\ClientMergeService;
use App\Services\MaxApiClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Воспроизведение и защита от дублей клиентов:
 * ручное создание → виджет → подтверждение в MAX/VK.
 */
class ClientPhoneIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Workspace $ws;

    private MasterService $masterService;

    private ClientMergeService $merge;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $this->merge = app(ClientMergeService::class);

        config([
            'legal.version' => 'v1',
            'services.max.secret_token' => 'max-webhook-secret',
            'services.max.bot_token' => 'max-bot-token',
            'booking.draft_ttl' => 3600,
        ]);

        $this->mockMaxApi();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'phone' => '79000000001',
            'is_service_provider' => true,
        ]);
        $this->ws = Workspace::create([
            'name' => 'WS Identity',
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
                ],
            );
        }

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 60,
        ]);
        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    private function mockMaxApi(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')->andReturnNull();
        $maxApi->shouldReceive('answerCallback')->andReturnNull();
        $maxApi->shouldReceive('answerCallbackWithMessage')->andReturnNull();
        $this->app->instance(MaxApiClient::class, $maxApi);
    }

    private function createManualClient(string $rawPhone, array $attrs = []): Client
    {
        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.clients.store'), array_merge([
                'name' => 'Анна Ручная',
                'phone' => $rawPhone,
                'notes' => 'важная заметка',
            ], $attrs));

        $response->assertOk();

        return Client::findOrFail($response->json('id'));
    }

    private function bookViaWidget(): Appointment
    {
        $response = $this->postJson(route('booking.reserve', $this->master->master_slug), [
            'service_id' => $this->masterService->id,
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'time' => '10:00',
            'provider' => 'telegram',
        ]);

        $response->assertOk();

        return Appointment::findOrFail($response->json('appointment_id'));
    }

    private function masterCreatesAppointmentFor(Client $client, string $time = '11:00'): Appointment
    {
        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.calendar.store'), [
                'client_id' => $client->id,
                'service_id' => $this->masterService->id,
                'date' => Carbon::tomorrow()->format('Y-m-d'),
                'time' => $time,
            ]);

        $response->assertStatus(302);

        return Appointment::where('client_id', $client->id)->firstOrFail();
    }

    /**
     * Подтверждение записи через публичный webhook MAX (contact attachment).
     */
    private function confirmViaMaxWebhook(
        Appointment $appointment,
        string $vcfPhone,
        string $senderId = 'max_uid_1',
        array $overrides = [],
    ): TestResponse {
        $vcf = "BEGIN:VCARD\nVERSION:3.0\nN:Ivanova;Anna;;;\nFN:Anna Ivanova\nTEL;TYPE=CELL:{$vcfPhone}\nEND:VCARD";
        $vcfNormalized = rtrim(preg_replace("/\r\n|\r|\n/", "\r\n", $vcf))."\r\n";
        $hash = hash_hmac('sha256', $vcfNormalized, 'max-bot-token');

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.$senderId, (string) $appointment->id, 3600);

        $payload = array_replace_recursive([
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => $senderId],
                'recipient' => ['chat_id' => 777],
                'body' => [
                    'mid' => 'mid_'.uniqid('', true),
                    'text' => '',
                    'attachments' => [[
                        'type' => 'contact',
                        'payload' => [
                            'vcf_info' => $vcf,
                            'max_info' => [
                                'user_id' => $senderId,
                                'first_name' => 'Анна',
                                'last_name' => 'Иванова',
                            ],
                            'hash' => $hash,
                        ],
                    ]],
                ],
            ],
        ], $overrides);

        return $this->postJson('/webhooks/max', $payload, [
            'X-Max-Bot-Api-Secret' => 'max-webhook-secret',
        ]);
    }

    /**
     * Подтверждение записи кнопкой (message_callback) через публичный webhook MAX.
     *
     * @return TestResponse<JsonResponse>
     */
    private function confirmViaMaxCallback(
        Appointment $appointment,
        string $userId,
        string $callbackId,
    ): TestResponse {
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.$userId, (string) $appointment->id, 3600);

        return $this->postJson('/webhooks/max', [
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => $callbackId,
                'payload' => 'max_confirm_'.$appointment->id,
                'user' => ['user_id' => $userId],
            ],
        ], [
            'X-Max-Bot-Api-Secret' => 'max-webhook-secret',
        ]);
    }

    private function vkSignedHeaders(string $vkUserId): array
    {
        $appId = '6736218';
        $appSecret = 'wvl68m4dR1UpLrVRli';
        config(['services.vk.app_id' => $appId, 'services.vk.app_secret' => $appSecret]);

        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $appId,
            'vk_platform' => 'android',
        ];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $hmac = hash_hmac('sha256', implode('&', $parts), $appSecret, true);
        $vkSign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));

        return ['Authorization' => 'Bearer '.http_build_query(array_merge($vkParams, ['sign' => $vkSign]))];
    }

    private function vkPhoneSign(string $vkUserId, string $rawPhone): string
    {
        $appId = '6736218';
        $appSecret = 'wvl68m4dR1UpLrVRli';
        $raw = hash('sha256', $appId.$appSecret.$vkUserId.'phone_number'.$rawPhone, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function confirmViaVk(Appointment $appointment, string $rawPhone, string $vkUserId = '494075'): TestResponse
    {
        $token = 'link_vk_test_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);
        Cache::put(CacheKeys::VK_CONSENT_PENDING.$vkUserId, config('legal.version'), 900);

        return $this->withHeaders($this->vkSignedHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $rawPhone,
                'sign' => $this->vkPhoneSign($vkUserId, $rawPhone),
            ]);
    }

    // ─── А. Основной сценарий: ручное создание → запись мастером → виджет → подтверждение ───

    public function test_manual_create_master_appointment_widget_confirm_via_max_reuses_client(): void
    {
        $client = $this->createManualClient('+7 (900) 123-45-67');
        $this->assertSame('79001234567', $client->phone);

        $masterAppointment = $this->masterCreatesAppointmentFor($client);

        $widgetAppointment = $this->bookViaWidget();
        $this->assertNull($widgetAppointment->client_id);

        $this->confirmViaMaxWebhook($widgetAppointment, '+79001234567')->assertOk();

        $widgetAppointment->refresh();
        $this->assertSame($client->id, $widgetAppointment->client_id);
        $this->assertSame(AppointmentSource::Max, $widgetAppointment->source);
        $this->assertSame($client->id, $masterAppointment->refresh()->client_id);

        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());

        $fresh = $client->fresh();
        $this->assertSame('Анна Ручная', $fresh->name);
        $this->assertSame('важная заметка', $fresh->notes);
        $this->assertSame('max_uid_1', $fresh->max_id);
        $this->assertFalse($fresh->is_blocked);
        $this->assertSame(2, $fresh->appointments()->count());
    }

    public function test_manual_create_master_appointment_widget_confirm_via_vk_reuses_client(): void
    {
        $client = $this->createManualClient('79001234567');

        $masterAppointment = $this->masterCreatesAppointmentFor($client);

        $widgetAppointment = $this->bookViaWidget();
        $this->assertNull($widgetAppointment->client_id);

        // VK может прислать 8-й префикс в подписанном номере.
        $this->confirmViaVk($widgetAppointment, '89001234567')->assertOk();

        $widgetAppointment->refresh();
        $this->assertSame($client->id, $widgetAppointment->client_id);
        $this->assertSame($client->id, $masterAppointment->refresh()->client_id);

        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());

        $fresh = $client->fresh();
        $this->assertSame('494075', $fresh->vk_id);
        $this->assertSame('Анна Ручная', $fresh->name);
        $this->assertSame('важная заметка', $fresh->notes);
        $this->assertSame(2, $fresh->appointments()->count());
    }

    // ─── А2. Международный номер: «+» переживает весь конвейер ───

    public function test_international_phone_keeps_plus_through_controller_storage_and_research(): void
    {
        // Контроллер: ручное создание хранит канонику со значимым «+».
        $client = $this->createManualClient('+84 912 345 678');
        $this->assertSame('+84912345678', $client->phone);

        // Сервис и повторный поиск: номер не превращается в российский.
        $found = $this->merge->findByPhone($this->master->id, '+84912345678', $this->ws->id);
        $this->assertNotNull($found);
        $this->assertSame($client->id, $found->id);

        $again = $this->merge->findOrCreateByPhone($this->master->id, '+84 912 345 678', '', null, $this->ws->id);
        $this->assertSame($client->id, $again->id);
        $this->assertSame('+84912345678', $again->fresh()->phone);

        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame(0, Client::where('phone', '74912345678')->count());
    }

    public function test_max_confirmation_with_international_phone_does_not_touch_russian_card(): void
    {
        // Отдельная российская карточка с «конфликтующими» цифрами.
        $russian = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '74912345678',
            'name' => 'Российский номер',
            'notes' => 'российские заметки',
        ]);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaMaxWebhook($widgetAppointment, '+84 912 345 678', 'max_uid_vn')->assertOk();

        $widgetAppointment->refresh();
        $this->assertNotNull($widgetAppointment->client_id);
        $this->assertNotSame($russian->id, $widgetAppointment->client_id);

        $intl = Client::find($widgetAppointment->client_id);
        $this->assertSame('+84912345678', $intl->phone);
        $this->assertSame('max_uid_vn', $intl->max_id);

        // Российская карточка не тронута и не «нашла» международный номер.
        $fresh = $russian->fresh();
        $this->assertSame('74912345678', $fresh->phone);
        $this->assertSame('Российский номер', $fresh->name);
        $this->assertSame('российские заметки', $fresh->notes);
        $this->assertNull($fresh->max_id);

        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());
    }

    public function test_vk_confirmation_with_international_phone_does_not_touch_russian_card(): void
    {
        $russian = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '74912345678',
            'name' => 'Российский номер',
        ]);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaVk($widgetAppointment, '+84912345678', '494075')->assertOk();

        $widgetAppointment->refresh();
        $this->assertNotSame($russian->id, $widgetAppointment->client_id);

        $intl = Client::find($widgetAppointment->client_id);
        $this->assertSame('+84912345678', $intl->phone);
        $this->assertSame('494075', $intl->vk_id);

        $fresh = $russian->fresh();
        $this->assertSame('74912345678', $fresh->phone);
        $this->assertNull($fresh->vk_id);

        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());
    }

    public function test_blocked_client_confirmation_via_max_is_refused_and_card_kept(): void
    {
        $client = $this->createManualClient('79001234567');
        $client->update(['is_blocked' => true]);

        $widgetAppointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($widgetAppointment, '+79001234567');

        $this->assertDatabaseMissing('appointments', ['id' => $widgetAppointment->id]);
        $fresh = $client->fresh();
        $this->assertTrue($fresh->is_blocked);
        $this->assertSame('Анна Ручная', $fresh->name);
        $this->assertSame('важная заметка', $fresh->notes);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    public function test_messenger_profile_name_does_not_replace_master_name_via_vk(): void
    {
        $client = $this->createManualClient('79001234567', ['name' => 'Анна Ручная']);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaVk($widgetAppointment, '79001234567')->assertOk();

        $this->assertSame('Анна Ручная', $client->fresh()->name);
    }

    // ─── Б. Один человек у двух независимых мастеров ───

    public function test_same_max_account_allowed_for_independent_masters(): void
    {
        $other = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000002']);

        $cardA = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Клиент у мастера A',
        ]);
        $this->merge->linkProvider($cardA, 'max', 'max_shared_uid');

        $cardB = Client::create([
            'user_id' => $other->id,
            'workspace_id' => null,
            'phone' => '79001234567',
            'name' => 'Тот же человек у мастера B',
        ]);

        // Один аккаунт MAX может принадлежать карточкам одного человека
        // у разных независимых мастеров.
        $this->merge->linkProvider($cardB, 'max', 'max_shared_uid');

        $this->assertSame('max_shared_uid', $cardA->fresh()->max_id);
        $this->assertSame('max_shared_uid', $cardB->fresh()->max_id);

        // Истории мастеров остаются раздельными.
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame(1, Client::where('user_id', $other->id)->count());
    }

    public function test_same_vk_account_allowed_for_independent_masters(): void
    {
        $other = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000003']);

        $cardA = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Клиент у мастера A',
        ]);
        $this->merge->linkProvider($cardA, 'vk', 'vk_shared_uid');

        $cardB = Client::create([
            'user_id' => $other->id,
            'workspace_id' => null,
            'phone' => '79001234567',
            'name' => 'Тот же человек у мастера B',
        ]);

        $this->merge->linkProvider($cardB, 'vk', 'vk_shared_uid');

        $this->assertSame('vk_shared_uid', $cardA->fresh()->vk_id);
        $this->assertSame('vk_shared_uid', $cardB->fresh()->vk_id);
    }

    public function test_provider_conflict_within_same_workspace_is_rejected(): void
    {
        $first = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110001',
            'name' => 'Первая',
        ]);
        $this->merge->linkProvider($first, 'max', 'max_uid_scope');

        $second = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110002',
            'name' => 'Вторая',
        ]);

        $this->expectException(ProviderLinkConflictException::class);
        $this->merge->linkProvider($second, 'max', 'max_uid_scope');
    }

    public function test_search_does_not_capture_cards_of_same_master_from_another_workspace(): void
    {
        $oldWs = Workspace::create(['name' => 'WS Old', 'owner_id' => $this->master->id]);

        $oldCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $oldWs->id,
            'phone' => '+79001234567',
            'name' => 'Старый воркспейс',
        ]);

        // В текущей области (новый workspace) карточки старого workspace нет.
        $this->assertNull($this->merge->findByPhone($this->master->id, '89001234567', $this->ws->id));

        $created = $this->merge->findOrCreateByPhone(
            $this->master->id,
            '89001234567',
            '',
            'Новая карточка',
            $this->ws->id,
        );

        $this->assertNotSame($oldCard->id, $created->id);
        $this->assertSame($this->ws->id, $created->workspace_id);
        $this->assertSame('+79001234567', $oldCard->fresh()->phone);
        $this->assertSame($oldWs->id, $oldCard->fresh()->workspace_id);
    }

    /**
     * Конфликт unique-индекса (user_id, phone) с карточкой ДРУГОГО
     * workspace: возврат этой карточки раскрыл бы её и выдал бы конфликт
     * за успех. Ожидается обработанный отказ без изменений.
     */
    public function test_unique_conflict_with_card_of_another_workspace_is_refused_without_disclosure(): void
    {
        $oldWs = Workspace::create(['name' => 'WS Old Raw', 'owner_id' => $this->master->id]);

        $oldCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $oldWs->id,
            'phone' => '79001234567',
            'name' => 'Тот же сырой номер',
            'notes' => 'чужая заметка',
            'max_id' => 'max_foreign_ws',
        ]);

        try {
            $this->merge->findOrCreateByPhone(
                $this->master->id,
                '89001234567',
                '',
                'Не должна появиться вторая',
                $this->ws->id,
            );
            $this->fail('Ожидался DuplicateClientPhoneException');
        } catch (DuplicateClientPhoneException) {
            // ожидаемый обработанный отказ
        }

        // Карточка другого workspace не раскрыта и не изменена.
        $fresh = $oldCard->fresh();
        $this->assertSame('79001234567', $fresh->phone);
        $this->assertSame($oldWs->id, $fresh->workspace_id);
        $this->assertSame('Тот же сырой номер', $fresh->name);
        $this->assertSame('чужая заметка', $fresh->notes);
        $this->assertSame('max_foreign_ws', $fresh->max_id);

        // Новой карточки в текущей области нет; транзакция не aborted.
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertNull($this->merge->findByPhone($this->master->id, '79001234567', $this->ws->id));
        $this->assertNotNull($this->merge->findByPhone($this->master->id, '+79001234567', $oldWs->id));
    }

    /**
     * Ручное создание: конфликт unique-индекса с карточкой другого
     * workspace нельзя выдать за успешное создание.
     */
    public function test_admin_store_conflict_with_another_workspace_card_is_refused_not_created(): void
    {
        $oldWs = Workspace::create(['name' => 'WS Old Store', 'owner_id' => $this->master->id]);

        $oldCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $oldWs->id,
            'phone' => '79001234567',
            'name' => 'Старая карточка',
        ]);

        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.clients.store'), [
                'name' => 'Поздний дубль',
                'phone' => '79001234567',
            ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey('phone', $errors);

        // Успешным созданием это не стало: ни новой карточки, ни возврата чужой.
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('Старая карточка', $oldCard->fresh()->name);
        $this->assertSame($oldWs->id, $oldCard->fresh()->workspace_id);
    }

    public function test_workspace_shared_client_is_reused_across_masters(): void
    {
        $masterB = User::factory()->master()->create([
            'workspace_id' => $this->ws->id,
            'role' => 'master',
            'phone' => '79000000004',
        ]);

        $shared = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Общий', $this->ws->id);

        $again = $this->merge->findOrCreateByPhone($masterB->id, '89001234567', '', null, $this->ws->id);
        $this->assertSame($shared->id, $again->id);
    }

    // ─── В. Legacy, неоднозначность, разные форматы ───

    public function test_russian_phone_variants_resolve_to_same_client(): void
    {
        $canonical = $this->merge->findOrCreateByPhone($this->master->id, '+7 (900) 123-45-67', '', 'Анна', $this->ws->id);

        $variants = [
            '79001234567',
            '+79001234567',
            '89001234567',
            '+8 900 123-45-67',
            '9001234567',
            '7 (900) 123-45-67',
            '8 (900) 123-45-67',
        ];

        foreach ($variants as $variant) {
            $found = $this->merge->findOrCreateByPhone($this->master->id, $variant, '', null, $this->ws->id);
            $this->assertSame($canonical->id, $found->id, "Variant {$variant} must reuse client");
        }

        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    public function test_ten_digit_legacy_phone_is_found_on_confirm(): void
    {
        // Уже сохранённая legacy-карточка с десятизначным номером
        // (формат с пробелами — путь regexp, а не точного whereIn).
        $legacy = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '900 123-45-67',
            'name' => 'Десять цифр',
            'notes' => 'старые заметки',
        ]);

        $found = $this->merge->findByPhone($this->master->id, '+79001234567', $this->ws->id);
        $this->assertNotNull($found);
        $this->assertSame($legacy->id, $found->id);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaMaxWebhook($widgetAppointment, '+79001234567')->assertOk();

        $this->assertSame($legacy->id, $widgetAppointment->refresh()->client_id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('старые заметки', $legacy->fresh()->notes);
    }

    public function test_legacy_formatted_phone_is_found(): void
    {
        $legacy = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+7 (900) 123-45-67',
            'name' => 'Legacy карточка',
            'notes' => 'старые заметки',
        ]);

        $found = $this->merge->findByPhone($this->master->id, '89001234567', $this->ws->id);
        $this->assertNotNull($found);
        $this->assertSame($legacy->id, $found->id);

        $viaService = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Новое имя', $this->ws->id);
        $this->assertSame($legacy->id, $viaService->id);
        $this->assertSame('Legacy карточка', $viaService->fresh()->name);
        $this->assertSame('старые заметки', $viaService->fresh()->notes);
    }

    public function test_legacy_null_workspace_client_reused_for_same_master(): void
    {
        $legacyMaster = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000005']);

        $legacy = Client::create([
            'user_id' => $legacyMaster->id,
            'workspace_id' => null,
            'phone' => '89001234567',
            'name' => 'До воркспейса',
        ]);

        // Мастер позже получил workspace — legacy карточка должна найтись.
        $ws = Workspace::create(['name' => 'WS Later', 'owner_id' => $legacyMaster->id]);
        $legacyMaster->update(['workspace_id' => $ws->id]);

        $found = $this->merge->findOrCreateByPhone(
            $legacyMaster->id,
            '+79001234567',
            '',
            null,
            $ws->id,
        );

        $this->assertSame($legacy->id, $found->id);
    }

    public function test_independent_masters_do_not_merge(): void
    {
        $other = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000006']);

        $clientA = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Мастер A клиент', null);
        $clientB = $this->merge->findOrCreateByPhone($other->id, '79001234567', '', 'Мастер B клиент', null);

        $this->assertNotSame($clientA->id, $clientB->id);
        $this->assertSame(2, Client::where('phone', '79001234567')->count());
    }

    public function test_ambiguous_duplicates_throw_instead_of_arbitrary_pick(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '+79001234567',
            'name' => 'Дубль плюс',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '89001234567',
            'name' => 'Дубль восемь',
        ]);

        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());

        $this->expectException(AmbiguousClientMatchException::class);
        $this->merge->findByPhone($this->master->id, '79001234567', null);
    }

    /**
     * Вместо «предпочтения канонической формы» — отказ при неоднозначности
     * без каких-либо побочных изменений карточек и привязок.
     */
    public function test_ambiguous_canonical_and_legacy_duplicates_rejected_without_side_effects(): void
    {
        $legacy = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '+79001234567',
            'name' => 'Legacy',
            'notes' => 'legacy заметка',
        ]);
        $canonical = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '79001234567',
            'name' => 'Канон',
            'notes' => 'канон заметка',
            'telegram_id' => 'tg_on_canonical',
        ]);

        $before = Client::orderBy('id')->get(['id', 'phone', 'name', 'notes', 'telegram_id', 'max_id', 'vk_id']);

        try {
            $this->merge->findByPhone($this->master->id, '79001234567', null);
            $this->fail('Ожидался AmbiguousClientMatchException');
        } catch (AmbiguousClientMatchException) {
            // ожидаемый отказ без выбора карточки
        }

        try {
            $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Новая', null);
            $this->fail('Ожидался AmbiguousClientMatchException');
        } catch (AmbiguousClientMatchException) {
            // ожидаемый отказ без создания третьей карточки
        }

        $after = Client::orderBy('id')->get(['id', 'phone', 'name', 'notes', 'telegram_id', 'max_id', 'vk_id']);

        $this->assertSame(2, $after->count());
        $this->assertEquals($before, $after);
        $this->assertSame($canonical->id, $canonical->fresh()->id);
        $this->assertSame('tg_on_canonical', $canonical->fresh()->telegram_id);
        $this->assertSame($legacy->id, $legacy->fresh()->id);
    }

    public function test_blocked_duplicate_is_not_ignored_in_ambiguous_match(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Заблокированный дубль',
            'is_blocked' => true,
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+79001234567',
            'name' => 'Незаблокированный дубль',
            'is_blocked' => false,
        ]);

        $widgetAppointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($widgetAppointment, '+79001234567');

        // Неоднозначность → отказ; незаблокированный дубль не выбирается молча.
        $this->assertNull($widgetAppointment->refresh()->client_id);
        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());
        $this->assertTrue(Client::where('phone', '79001234567')->first()->is_blocked);
        $this->assertFalse(Client::where('phone', '+79001234567')->first()->is_blocked);
    }

    // ─── Г. Привязки ───

    public function test_telegram_id_conflict_is_rejected_without_overwrite(): void
    {
        Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79009998877',
            'telegram_id' => '555001',
            'name' => 'Чужая карточка TG',
        ]);

        $this->expectException(ProviderLinkConflictException::class);

        $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '555001', 'Пришла', $this->ws->id);
    }

    public function test_late_link_request_does_not_overwrite_existing_binding(): void
    {
        $client = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Карточка',
        ]);

        // Модель, загруженная до того, как «конкурентный» запрос успел привязать.
        $stale = Client::find($client->id);
        Client::whereKey($client->id)->update(['vk_id' => 'vk_won_the_race']);

        try {
            $this->merge->linkProvider($stale, 'vk', 'vk_late_arrival');
            $this->fail('Ожидался ProviderLinkConflictException');
        } catch (ProviderLinkConflictException) {
            // ожидаемый конфликт
        }

        $this->assertSame('vk_won_the_race', $client->fresh()->vk_id);
    }

    public function test_link_provider_is_idempotent_for_same_id(): void
    {
        $client = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Карточка',
            'vk_id' => 'vk_same',
        ]);

        $result = $this->merge->linkProvider($client, 'vk', 'vk_same');
        $this->assertSame('vk_same', $result->fresh()->vk_id);
    }

    public function test_vk_provider_conflict_returns_409_and_keeps_existing_link(): void
    {
        $existing = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'vk_id' => '111222',
            'name' => 'С привязкой',
        ]);

        $otherAppointment = $this->bookViaWidget();

        $this->confirmViaVk($otherAppointment, '79001234567', '494075')->assertStatus(409);

        $this->assertSame('111222', $existing->fresh()->vk_id);
        $this->assertNull($otherAppointment->refresh()->client_id);
    }

    public function test_vk_link_does_not_attach_account_to_another_card_of_same_scope(): void
    {
        // Уже привязанный к другому номеру этого же мастера vk_id.
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110001',
            'vk_id' => '494075',
            'name' => 'Прежний номер',
        ]);

        $appointment = $this->bookViaWidget();

        // Подписанный номер указывает на другую карточку — привязка занята.
        $this->confirmViaVk($appointment, '79001234567', '494075')->assertStatus(409);

        $this->assertSame(1, Client::where('vk_id', '494075')->count());
        $this->assertNull($appointment->refresh()->client_id);
        $this->assertNull(Client::where('phone', '79001234567')->first()?->vk_id);
    }

    public function test_vk_unsigned_phone_is_rejected(): void
    {
        $appointment = $this->bookViaWidget();

        $token = 'link_vk_badsign_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);
        Cache::put(CacheKeys::VK_CONSENT_PENDING.'494075', config('legal.version'), 900);

        $this->withHeaders($this->vkSignedHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => '79001234567',
                'sign' => 'invalid-sign',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Client::where('phone', '79001234567')->count());
        $this->assertNull($appointment->refresh()->client_id);
    }

    public function test_vk_repeated_delivery_is_idempotent(): void
    {
        $appointment = $this->bookViaWidget();

        $this->confirmViaVk($appointment, '79001234567', '494075')->assertOk();
        $appointment->refresh();
        $clientId = $appointment->client_id;
        $this->assertNotNull($clientId);

        // Повторная доставка того же события (токен уже погашен) — отказ без изменений.
        $this->confirmViaVk($appointment, '79001234567', '494075')->assertStatus(422);

        $this->assertSame($clientId, $appointment->refresh()->client_id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    public function test_max_contact_with_invalid_hash_is_rejected_via_public_webhook(): void
    {
        $appointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($appointment, '+79001234567', 'max_uid_1', [
            'message' => ['body' => ['attachments' => [[
                'payload' => ['hash' => 'invalid-hash-value'],
            ]]]],
        ]);

        $this->assertNull($appointment->refresh()->client_id);
        $this->assertSame(0, Client::where('user_id', $this->master->id)->count());
    }

    public function test_max_contact_not_belonging_to_sender_is_rejected_via_public_webhook(): void
    {
        $appointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($appointment, '+79001234567', 'max_uid_sender', [
            'message' => ['body' => ['attachments' => [[
                'payload' => ['max_info' => ['user_id' => 'max_uid_someone_else']],
            ]]]],
        ]);

        // Чужой контакт не даёт права привязать свой аккаунт и брать карточку.
        $this->assertNull($appointment->refresh()->client_id);
        $this->assertSame(0, Client::where('user_id', $this->master->id)->count());
        $this->assertSame(0, Client::where('max_id', 'max_uid_sender')->count());
    }

    public function test_max_provider_conflict_via_public_webhook_keeps_appointment_unconfirmed(): void
    {
        // max_id уже привязан к другой карточке этого же мастера.
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110001',
            'max_id' => 'max_uid_conflict',
            'name' => 'Прежний номер',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        $appointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($appointment, '+79001234567', 'max_uid_conflict');

        $this->assertNull($appointment->refresh()->client_id);
        $this->assertSame('max_uid_conflict', Client::where('phone', '79001110001')->first()->max_id);
        // Карточки с новым номером не осталось (частичных изменений нет).
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    public function test_max_repeated_delivery_is_idempotent(): void
    {
        $appointment = $this->bookViaWidget();

        $this->confirmViaMaxWebhook($appointment, '+79001234567', 'max_uid_1')->assertOk();
        $appointment->refresh();
        $clientId = $appointment->client_id;
        $this->assertNotNull($clientId);

        // Повторная доставка того же событие гасится дедупликацией по mid.
        // Отправляем второй раз с тем же mid — payload идентичен.
        $vcf = "BEGIN:VCARD\nVERSION:3.0\nN:Ivanova;Anna;;;\nFN:Anna Ivanova\nTEL;TYPE=CELL:+79001234567\nEND:VCARD";
        $vcfNormalized = rtrim(preg_replace("/\r\n|\r|\n/", "\r\n", $vcf))."\r\n";
        $hash = hash_hmac('sha256', $vcfNormalized, 'max-bot-token');
        $mid = 'mid_fixed_for_replay';

        $payload = [
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => 'max_uid_1'],
                'recipient' => ['chat_id' => 777],
                'body' => [
                    'mid' => $mid,
                    'text' => '',
                    'attachments' => [[
                        'type' => 'contact',
                        'payload' => [
                            'vcf_info' => $vcf,
                            'max_info' => ['user_id' => 'max_uid_1', 'first_name' => 'Анна', 'last_name' => 'Иванова'],
                            'hash' => $hash,
                        ],
                    ]],
                ],
            ],
        ];

        // Первый delivery с фиксированным mid (уникальный в тесте).
        $this->postJson('/webhooks/max', $payload, ['X-Max-Bot-Api-Secret' => 'max-webhook-secret'])->assertOk();
        $this->postJson('/webhooks/max', $payload, ['X-Max-Bot-Api-Secret' => 'max-webhook-secret'])->assertOk();

        $this->assertSame($clientId, $appointment->refresh()->client_id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    // ─── MAX: подтверждается только ещё не привязанная запись ───

    public function test_max_contact_after_cancellation_refuses_without_side_effects(): void
    {
        $widgetAppointment = $this->bookViaWidget();
        $widgetAppointment->update(['status' => AppointmentStatus::Cancelled]);

        Cache::put(CacheKeys::MAX_CONSENT_PENDING.'max_uid_1', config('legal.version'), 3600);

        $this->confirmViaMaxWebhook($widgetAppointment, '+79001234567');

        $widgetAppointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $widgetAppointment->status);
        $this->assertNull($widgetAppointment->client_id);

        // Отказ откатывает всё: ни карточки, ни привязки, ни погашенного
        // отложенного согласия.
        $this->assertSame(0, Client::where('user_id', $this->master->id)->count());
        $this->assertSame(0, Client::where('max_id', 'max_uid_1')->count());
        $this->assertSame(config('legal.version'), Cache::get(CacheKeys::MAX_CONSENT_PENDING.'max_uid_1'));
    }

    public function test_late_max_contact_does_not_relink_already_confirmed_appointment(): void
    {
        $ownerCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Первый',
            'max_id' => 'max_uid_owner',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_owner', 'cb_owner')->assertOk();

        $widgetAppointment->refresh();
        $this->assertSame($ownerCard->id, $widgetAppointment->client_id);

        // Запоздалое подтверждение другим аккаунтом со своим номером.
        $this->confirmViaMaxWebhook($widgetAppointment, '79009998877', 'max_uid_late');

        $widgetAppointment->refresh();
        $this->assertSame($ownerCard->id, $widgetAppointment->client_id, 'запоздалое подтверждение не перепривязывает запись');
        $this->assertSame(AppointmentSource::Max, $widgetAppointment->source);
        $this->assertSame(AppointmentStatus::Booked, $widgetAppointment->status);

        // При отказе не осталось ни новой карточки, ни привязки, ни
        // изменений карточки/согласия/привязки победителя.
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame(0, Client::where('max_id', 'max_uid_late')->count());

        $fresh = $ownerCard->fresh();
        $this->assertSame('79001234567', $fresh->phone);
        $this->assertSame('Первый', $fresh->name);
        $this->assertSame('max_uid_owner', $fresh->max_id);
        $this->assertSame('v1', $fresh->pdn_consent_version);
    }

    public function test_repeated_same_max_confirmation_has_single_effect(): void
    {
        $card = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Повтор',
            'max_id' => 'max_uid_1',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        $widgetAppointment = $this->bookViaWidget();

        Event::fake();

        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_1', 'cb_first')->assertOk();

        $widgetAppointment->refresh();
        $clientId = $widgetAppointment->client_id;
        $this->assertSame($card->id, $clientId);
        Event::assertDispatchedTimes(AppointmentCreated::class, 1);

        // Повтор того же подтверждения (черновик открыт заново тем же
        // пользователем): успех без повторных эффектов.
        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_1', 'cb_repeat');

        $widgetAppointment->refresh();
        $this->assertSame($clientId, $widgetAppointment->client_id);
        Event::assertDispatchedTimes(AppointmentCreated::class, 1);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('max_uid_1', $card->fresh()->max_id);
    }

    public function test_two_different_max_confirmations_of_one_appointment_single_winner(): void
    {
        $cardA = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Первый',
            'max_id' => 'max_uid_a',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);
        $cardB = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110002',
            'name' => 'Второй',
            'max_id' => 'max_uid_b',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        $widgetAppointment = $this->bookViaWidget();

        Event::fake();

        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_a', 'cb_a')->assertOk();

        $widgetAppointment->refresh();
        $this->assertSame($cardA->id, $widgetAppointment->client_id);

        // Второе подтверждение того же appointment другим аккаунтом.
        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_b', 'cb_b');

        $widgetAppointment->refresh();
        $this->assertSame($cardA->id, $widgetAppointment->client_id);
        $this->assertSame(AppointmentStatus::Booked, $widgetAppointment->status);

        // Карточка второго участника без изменений; эффект подтверждения один.
        $this->assertSame('79001110002', $cardB->fresh()->phone);
        $this->assertSame('max_uid_b', $cardB->fresh()->max_id);
        $this->assertSame('Второй', $cardB->fresh()->name);
        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());

        Event::assertDispatchedTimes(AppointmentCreated::class, 1);
    }

    public function test_blocked_foreign_confirmation_does_not_delete_claimed_appointment(): void
    {
        $ownerCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Владелец',
            'max_id' => 'max_uid_owner',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);
        $blockedCard = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001110003',
            'name' => 'Заблокированный',
            'max_id' => 'max_uid_blocked',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
            'is_blocked' => true,
        ]);

        $widgetAppointment = $this->bookViaWidget();
        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_owner', 'cb_owner');

        $widgetAppointment->refresh();
        $this->assertSame($ownerCard->id, $widgetAppointment->client_id);

        // Чужое запоздалое подтверждение заблокированного профиля не
        // удаляет и не трогает уже занятую запись.
        $this->confirmViaMaxCallback($widgetAppointment, 'max_uid_blocked', 'cb_blocked');

        $this->assertDatabaseHas('appointments', [
            'id' => $widgetAppointment->id,
            'client_id' => $ownerCard->id,
            'status' => AppointmentStatus::Booked,
        ]);
        $this->assertSame('Заблокированный', $blockedCard->fresh()->name);
        $this->assertTrue($blockedCard->fresh()->is_blocked);
    }

    // ─── Админ: ручное создание и изменение ───

    public function test_admin_store_rejects_duplicate_in_another_format(): void
    {
        $this->createManualClient('79001234567');

        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.clients.store'), [
                'name' => 'Дубль',
                'phone' => '8 (900) 123-45-67',
            ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey('phone', $errors);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    public function test_admin_store_rejects_ambiguous_duplicates_without_changes(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+79001234567',
            'name' => 'Дубль 1',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '89001234567',
            'name' => 'Дубль 2',
        ]);

        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.clients.store'), [
                'name' => 'Третий',
                'phone' => '79001234567',
            ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(2, Client::where('user_id', $this->master->id)->count());
    }

    public function test_admin_update_phone_uses_shared_scope_and_lock(): void
    {
        $client = $this->createManualClient('79001234567');
        $other = $this->createManualClient('79009998877', ['name' => 'Другая']);

        // Смена на номер другой карточки — отказ, данные не меняются.
        $response = $this->actingAs($this->master, 'web')
            ->putJson(route('admin.clients.update', $client->id), [
                'name' => 'Анна Ручная',
                'phone' => '8 (900) 999-88-77',
                'notes' => 'важная заметка',
            ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('79001234567', $client->fresh()->phone);
        $this->assertSame($other->fresh()->phone, '79009998877');

        // Успешная смена телефона нормализует номер.
        $response = $this->actingAs($this->master, 'web')
            ->putJson(route('admin.clients.update', $client->id), [
                'name' => 'Анна Ручная',
                'phone' => '8 (900) 111-22-33',
                'notes' => 'важная заметка',
            ]);

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('79001112233', $client->fresh()->phone);
        $this->assertSame('важная заметка', $client->fresh()->notes);
    }

    // ─── RoleSwitch: регрессии без смены владельца ───

    public function test_role_switch_to_client_does_not_change_card_owner(): void
    {
        $owner = User::factory()->master()->create([
            'workspace_id' => $this->ws->id,
            'role' => 'owner',
            'phone' => '79000000010',
        ]);

        // Карточка в общем workspace с телефоном второго мастера,
        // но владельцем остаётся owner.
        $sharedCard = Client::create([
            'user_id' => $owner->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79000000011',
            'name' => 'Общий клиент',
        ]);

        $member = User::factory()->master()->create([
            'workspace_id' => $this->ws->id,
            'role' => 'master',
            'phone' => '79000000011',
        ]);

        $response = $this->actingAs($member, 'web')->post(route('switch.to.client'));

        $response->assertRedirect();

        $fresh = $sharedCard->fresh();
        $this->assertSame($owner->id, $fresh->user_id, 'Владелец карточки не должен меняться');
        $this->assertAuthenticatedAs($fresh, 'client');
    }

    // ─── RoleSwitch: полный переход мастер → клиент → мастер ───

    public function test_role_switch_round_trip_with_plus_phone_restores_master(): void
    {
        $master = User::factory()->master()->create([
            'phone' => '+79001234567',
        ]);

        $this->actingAs($master, 'web')->post(route('switch.to.client'))
            ->assertRedirect(route('client.profile'));

        $client = Client::where('user_id', $master->id)->first();
        $this->assertNotNull($client, 'toClient должен создать новую карточку');
        $this->assertSame('79001234567', $client->phone);
        $this->assertAuthenticatedAs($client, 'client');

        $this->post(route('switch.to.master'))
            ->assertRedirect(route('admin.calendar'));

        $this->assertAuthenticatedAs($master, 'web');
    }

    public function test_role_switch_round_trip_with_plain_phone_restores_master(): void
    {
        $master = User::factory()->master()->create([
            'phone' => '79001234567',
        ]);

        $this->actingAs($master, 'web')->post(route('switch.to.client'))
            ->assertRedirect(route('client.profile'));

        $client = Client::where('user_id', $master->id)->first();
        $this->assertNotNull($client, 'toClient должен создать новую карточку');
        $this->assertSame('79001234567', $client->phone);
        $this->assertAuthenticatedAs($client, 'client');

        $this->post(route('switch.to.master'))
            ->assertRedirect(route('admin.calendar'));

        $this->assertAuthenticatedAs($master, 'web');
    }

    public function test_role_switch_to_master_refuses_ambiguous_accounts_with_same_number(): void
    {
        $master = User::factory()->master()->create([
            'phone' => '+79001234567',
        ]);
        User::factory()->master()->create([
            'phone' => '89001234567',
        ]);

        $this->actingAs($master, 'web')->post(route('switch.to.client'))
            ->assertRedirect(route('client.profile'));

        $client = Client::where('user_id', $master->id)->first();
        $this->assertNotNull($client);
        $this->assertSame('79001234567', $client->phone);

        $this->post(route('switch.to.master'))
            ->assertRedirect(route('auth.choose'))
            ->assertSessionHas(
                'info',
                'Найдено несколько аккаунтов с вашим номером. Обратитесь в поддержку.',
            );

        $this->assertAuthenticatedAs($master, 'web');
    }

    // ─── Диагностическая команда ───

    public function test_diagnostic_command_reports_duplicates_without_secrets(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '+79001234567',
            'name' => 'Дубль 1',
            'telegram_id' => '999888777',
            'auth_token' => 'secret-token-should-not-appear',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '89001234567',
            'name' => 'Дубль 2',
            'max_id' => 'max_secret_id',
        ]);

        $this->artisan('clients:find-duplicates')
            ->expectsOutputToContain('Найдено групп-дублей: 1')
            ->doesntExpectOutputToContain('secret-token-should-not-appear')
            ->doesntExpectOutputToContain('max_secret_id')
            ->doesntExpectOutputToContain('999888777')
            ->assertExitCode(0);
    }

    public function test_diagnostic_command_groups_workspace_and_legacy_cards_of_same_master_together(): void
    {
        // Карточка workspace и legacy-карточка того же мастера — одна область
        // рабочего поиска, команда должна увидеть неоднозначность.
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+79001234567',
            'name' => 'Воркспейс-дубль',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '89001234567',
            'name' => 'Legacy-дубль',
        ]);

        $this->artisan('clients:find-duplicates')
            ->expectsOutputToContain('Найдено групп-дублей: 1')
            ->assertExitCode(0);
    }

    public function test_diagnostic_command_workspace_filter_excludes_foreign_legacy_cards(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+79001234567',
            'name' => 'WS карточка',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '89001234567',
            'name' => 'Legacy этого мастера',
        ]);

        $foreignMaster = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000012']);
        $foreign = Client::create([
            'user_id' => $foreignMaster->id,
            'workspace_id' => null,
            'phone' => '79001234567',
            'name' => 'Чужой legacy',
        ]);

        // В области workspace есть неоднозначность (2 карточки),
        // но чужая legacy-карточка в вывод не попадает.
        $this->artisan('clients:find-duplicates --workspace='.$this->ws->id)
            ->expectsOutputToContain('Найдено групп-дублей: 1')
            ->doesntExpectOutputToContain($foreign->id)
            ->assertExitCode(0);
    }

    public function test_diagnostic_command_is_read_only_and_masks_phones(): void
    {
        $card = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'name' => 'Дубль 1',
        ]);
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '89001234567',
            'name' => 'Дубль 2',
        ]);

        Queue::fake();

        $before = Client::orderBy('id')->get(['id', 'phone', 'name', 'is_blocked', 'updated_at']);

        $this->artisan('clients:find-duplicates')
            ->expectsOutputToContain('Найдено групп-дублей: 1')
            ->assertExitCode(0);

        $after = Client::orderBy('id')->get(['id', 'phone', 'name', 'is_blocked', 'updated_at']);
        $this->assertEquals($before, $after, 'Команда не должна менять данные');
        Queue::assertNothingPushed();

        // Полный номер не раскрывается.
        $this->artisan('clients:find-duplicates')
            ->doesntExpectOutputToContain('79001234567')
            ->assertExitCode(0);

        $this->assertNotNull($card->fresh());
    }
}
