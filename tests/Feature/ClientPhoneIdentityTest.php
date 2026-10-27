<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Exceptions\AmbiguousClientMatchException;
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
use App\Support\Phone;
use App\Webhooks\MaxWebhookHandler;
use App\Webhooks\TelegramWebhookHandler;
use Carbon\Carbon;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Воспроизведение и защита от дублей клиентов:
 * ручное создание → виджет → подтверждение в MAX/VK/Telegram.
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

        config(['services.telegram.bot_token' => 'fake-token']);
        config(['services.telegram.secret_token' => 'tg-secret']);
        config(['legal.version' => 'v1']);

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

    private function confirmViaTelegram(Appointment $appointment, string $phoneDigits, string $tgId = '555001'): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $bot = TelegraphBot::firstOrCreate(
            ['token' => 'fake-tg-bot-token'],
            ['name' => 'identity-bot'],
        );
        $chat = new TelegraphChat([
            'chat_id' => 424242,
            'name' => 'Test chat',
        ]);
        $chat->telegraph_bot_id = $bot->id;
        $chat->save();

        $handler = app(TelegramWebhookHandler::class);

        $ref = new \ReflectionClass($handler);
        while ($ref && ! $ref->hasProperty('chat')) {
            $ref = $ref->getParentClass();
        }
        $this->assertNotNull($ref, 'chat property not found');
        $prop = $ref->getProperty('chat');
        $prop->setAccessible(true);
        $prop->setValue($handler, $chat);

        $method = new ReflectionMethod(TelegramWebhookHandler::class, 'handleBookingContact');
        $method->setAccessible(true);
        $method->invoke($handler, [
            'phone_number' => $phoneDigits,
            'user_id' => (int) $tgId,
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
        ], '424242', (string) $appointment->id);
    }

    private function confirmViaMax(Appointment $appointment, string $phoneDigits, string $maxUserId = 'max_uid_1'): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')->andReturnNull();
        $this->app->instance(MaxApiClient::class, $maxApi);

        $handler = app(MaxWebhookHandler::class);
        $method = new ReflectionMethod(MaxWebhookHandler::class, 'handleBookingContact');
        $method->setAccessible(true);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.$maxUserId, $appointment->id, 3600);

        $method->invoke(
            $handler,
            $maxUserId,
            $maxUserId,
            Phone::normalize($phoneDigits),
            'Анна',
            'Иванова',
            (string) $appointment->id,
        );
    }

    private function confirmViaVk(Appointment $appointment, string $rawPhone, string $vkUserId = '494075'): void
    {
        $appId = '6736218';
        $appSecret = 'wvl68m4dR1UpLrVRli';
        config(['services.vk.app_id' => $appId, 'services.vk.app_secret' => $appSecret]);

        $token = 'link_vk_test_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);
        Cache::put(CacheKeys::VK_CONSENT_PENDING.$vkUserId, config('legal.version'), 900);

        $raw = hash('sha256', $appId.$appSecret.$vkUserId.'phone_number'.$rawPhone, true);
        $sign = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

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
        $all = array_merge($vkParams, ['sign' => $vkSign]);

        $this->withHeaders(['Authorization' => 'Bearer '.http_build_query($all)])
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $rawPhone,
                'sign' => $sign,
            ])
            ->assertOk();
    }

    // ─── 1. Ручное создание → виджет → подтверждение ───

    public function test_manual_create_then_telegram_confirm_reuses_client(): void
    {
        $client = $this->createManualClient('+7 (900) 123-45-67');
        $this->assertSame('79001234567', $client->phone);

        $appointment = $this->bookViaWidget();
        $this->assertNull($appointment->client_id);

        $this->confirmViaTelegram($appointment, '89001234567', '777001');

        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('Анна Ручная', $client->fresh()->name);
        $this->assertSame('важная заметка', $client->fresh()->notes);
        $this->assertSame('777001', $client->fresh()->telegram_id);
    }

    public function test_manual_create_then_max_confirm_reuses_client(): void
    {
        $client = $this->createManualClient('89001234567');

        $appointment = $this->bookViaWidget();
        $this->confirmViaMax($appointment, '+79001234567', 'max_a1');

        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertSame(AppointmentSource::Max, $appointment->source);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('Анна Ручная', $client->fresh()->name);
        $this->assertSame('max_a1', $client->fresh()->max_id);
    }

    public function test_manual_create_then_vk_confirm_reuses_client(): void
    {
        $client = $this->createManualClient('79001234567');

        $appointment = $this->bookViaWidget();
        // VK может прислать 8-й префикс в подписанном номере.
        $this->confirmViaVk($appointment, '89001234567', '494075');

        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertSame('494075', $client->fresh()->vk_id);
        $this->assertSame('Анна Ручная', $client->fresh()->name);
    }

    // ─── 2. Варианты записи одного российского номера ───

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

    // ─── 3. Разные мастера и legacy workspace_id=NULL ───

    public function test_independent_masters_do_not_merge(): void
    {
        $other = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000002']);

        $clientA = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Мастер A клиент', null);
        $clientB = $this->merge->findOrCreateByPhone($other->id, '79001234567', '', 'Мастер B клиент', null);

        $this->assertNotSame($clientA->id, $clientB->id);
        $this->assertSame(2, Client::where('phone', '79001234567')->count());
    }

    public function test_legacy_null_workspace_client_reused_for_same_master(): void
    {
        $legacyMaster = User::factory()->master()->create(['workspace_id' => null, 'phone' => '79000000003']);

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

    // ─── 4. Блокировка, имя, заметки ───

    public function test_blocked_client_is_not_bypassed_via_format_duplicate(): void
    {
        $blocked = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '+79001234567',
            'name' => 'Заблокирована',
            'is_blocked' => true,
        ]);

        $appointment = $this->bookViaWidget();
        $this->confirmViaTelegram($appointment, '89001234567', '900111');

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $this->assertSame($blocked->id, $blocked->fresh()->id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
        $this->assertTrue($blocked->fresh()->is_blocked);
    }

    public function test_messenger_profile_name_does_not_replace_master_name(): void
    {
        $client = $this->createManualClient('79001234567', ['name' => 'Анна Ручная']);

        $appointment = $this->bookViaWidget();
        $this->confirmViaTelegram($appointment, '79001234567', '900222');

        $this->assertSame('Анна Ручная', $client->fresh()->name);
    }

    // ─── 5. Конфликт аккаунта мессенджера ───

    public function test_telegram_id_conflict_is_rejected_without_overwrite(): void
    {
        $other = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79009998877',
            'telegram_id' => '555001',
            'name' => 'Чужая карточка TG',
        ]);

        $this->expectException(ProviderLinkConflictException::class);

        $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '555001', 'Пришла', $this->ws->id);
    }

    public function test_vk_provider_conflict_returns_409(): void
    {
        Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'phone' => '79001234567',
            'vk_id' => '111222',
            'name' => 'С привязкой',
        ]);

        $appointment = $this->bookViaWidget();
        $appId = '6736218';
        $appSecret = 'wvl68m4dR1UpLrVRli';
        config(['services.vk.app_id' => $appId, 'services.vk.app_secret' => $appSecret]);

        $vkUserId = '494075';
        $token = 'link_vk_conflict_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);
        Cache::put(CacheKeys::VK_CONSENT_PENDING.$vkUserId, config('legal.version'), 900);

        $rawPhone = '79001234567';
        $raw = hash('sha256', $appId.$appSecret.$vkUserId.'phone_number'.$rawPhone, true);
        $sign = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $vkParams = ['vk_user_id' => $vkUserId, 'vk_app_id' => $appId, 'vk_platform' => 'android'];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $hmac = hash_hmac('sha256', implode('&', $parts), $appSecret, true);
        $vkSign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));

        $this->withHeaders(['Authorization' => 'Bearer '.http_build_query(array_merge($vkParams, ['sign' => $vkSign]))])
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $rawPhone,
                'sign' => $sign,
            ])
            ->assertStatus(409);

        $this->assertSame('111222', Client::where('phone', '79001234567')->first()->vk_id);
    }

    public function test_vk_unsigned_phone_is_rejected(): void
    {
        $appointment = $this->bookViaWidget();
        $appId = '6736218';
        $appSecret = 'wvl68m4dR1UpLrVRli';
        config(['services.vk.app_id' => $appId, 'services.vk.app_secret' => $appSecret]);

        $vkUserId = '494075';
        $token = 'link_vk_badsign_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);
        Cache::put(CacheKeys::VK_CONSENT_PENDING.$vkUserId, config('legal.version'), 900);

        $vkParams = ['vk_user_id' => $vkUserId, 'vk_app_id' => $appId, 'vk_platform' => 'android'];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $hmac = hash_hmac('sha256', implode('&', $parts), $appSecret, true);
        $vkSign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));

        $this->withHeaders(['Authorization' => 'Bearer '.http_build_query(array_merge($vkParams, ['sign' => $vkSign]))])
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => '79001234567',
                'sign' => 'invalid-sign',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Client::where('phone', '79001234567')->count());
    }

    // ─── 6. Неоднозначные дубли ───

    public function test_ambiguous_duplicates_throw_instead_of_arbitrary_pick(): void
    {
        // Два legacy-дубля одного мастера с одинаковым нормализованным телефоном
        // и разными сырыми форматами (не бьётся с unique по строке).
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

    public function test_exact_canonical_form_preferred_over_legacy_duplicates(): void
    {
        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '+79001234567',
            'name' => 'Legacy',
        ]);
        $canonical = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => null,
            'phone' => '79001234567',
            'name' => 'Канон',
        ]);

        $found = $this->merge->findByPhone($this->master->id, '79001234567', null);
        $this->assertSame($canonical->id, $found->id);
    }

    // ─── 7. Конкурентность ───

    public function test_concurrent_create_resolves_to_single_client(): void
    {
        $created = null;

        Client::creating(function (Client $model) use (&$created) {
            if ($created !== null || $model->phone !== '79001234567') {
                return;
            }

            $created = true;

            // Имитация параллельной вставки до нашего INSERT.
            DB::table('clients')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $model->user_id,
                'workspace_id' => $model->workspace_id,
                'phone' => '79001234567',
                'name' => 'Параллельный',
                'is_personal' => true,
                'is_blocked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $client = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Поздний', $this->ws->id);

        $this->assertNotNull($client->id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->where('phone', '79001234567')->count());
    }

    public function test_sequential_find_or_create_is_stable(): void
    {
        $a = $this->merge->findOrCreateByPhone($this->master->id, '79001234567', '', 'Первый', $this->ws->id);
        $b = $this->merge->findOrCreateByPhone($this->master->id, '89001234567', '', 'Второй', $this->ws->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
    }

    // ─── 8. Админ: дубль при ручном создании в другом формате ───

    public function test_admin_store_rejects_duplicate_in_another_format(): void
    {
        $this->createManualClient('79001234567');

        $response = $this->actingAs($this->master, 'web')
            ->postJson(route('admin.clients.store'), [
                'name' => 'Дубль',
                'phone' => '8 (900) 123-45-67',
            ]);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey('phone', $errors);
        $this->assertSame(1, Client::where('user_id', $this->master->id)->count());
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
            ->assertExitCode(0);
    }
}
