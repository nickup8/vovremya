<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\VkIdOAuthService;
use App\Services\MasterSlugCreateRetry;
use App\Services\MaxApiClient;
use App\Services\SlugService;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use Tests\TestCase;

/**
 * N10: повтор генерации master_slug при users_master_slug_unique
 * в путях авторизации ВК / MAX / Telegram.
 */
class MasterSlugUniqueRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> UUID строк, вставленных на отдельном соединении */
    private array $raceUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        config([
            'legal.version' => 'v-test',
            'services.max.secret_token' => 'max-webhook-secret',
            'services.max.bot_token' => 'max-bot-token',
            'services.telegram.secret_token' => 'tg-webhook-secret',
            'services.telegram.bot_token' => 'fake-tg-token',
            'booking.token_ttl' => 3600,
        ]);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => ['total_count' => 0]]),
        ]);
    }

    protected function tearDown(): void
    {
        $raceUserIds = $this->raceUserIds;
        $this->raceUserIds = [];
        $pgsqlConfig = config('database.connections.pgsql');

        // Сначала завершаем транзакции теста (RefreshDatabase),
        // затем адресно удаляем гоночные строки по ID на отдельном PDO.
        parent::tearDown();

        if ($raceUserIds !== []) {
            $this->deleteUsersOnSeparateConnection($raceUserIds, $pgsqlConfig);
        }
    }

    // ── Detection ─────────────────────────────────────────

    public function test_detects_russian_locale_master_slug_unique_message(): void
    {
        $e = $this->makeUniqueViolation(
            'SQLSTATE[23505]: Unique violation: 7 ОШИБКА:  повторяющееся значение ключа '
            .'нарушает ограничение уникальности "users_master_slug_unique"'
            ."\nDETAIL:  Ключ \"(master_slug)=(ivan)\" уже существует.",
            '23505',
        );

        $this->assertTrue(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    public function test_detects_from_previous_message_without_sql_insert_text(): void
    {
        $previous = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_master_slug_unique"',
        );
        $previous->errorInfo = ['23505', null, 'users_master_slug_unique'];
        $reflection = new \ReflectionProperty(PDOException::class, 'code');
        $reflection->setValue($previous, '23505');

        $e = new UniqueConstraintViolationException(
            'pgsql',
            'insert into "users" ("master_slug") values (?)',
            ['ivan'],
            $previous,
        );
        $e->errorInfo = ['23505', null, 'users_master_slug_unique'];

        $this->assertTrue(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    public function test_rejects_phone_unique_violation(): void
    {
        $e = $this->makeUniqueViolation(
            'SQLSTATE[23505]: Unique violation: 7 ОШИБКА:  повторяющееся значение ключа '
            .'нарушает ограничение уникальности "users_phone_unique"',
            '23505',
        );

        $this->assertFalse(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    public function test_rejects_when_constraint_name_only_in_sql_insert_text(): void
    {
        $previous = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ОШИБКА:  повторяющееся значение ключа '
            .'нарушает ограничение уникальности "users_phone_unique"',
        );
        $previous->errorInfo = ['23505', null, 'users_phone_unique'];
        $reflection = new \ReflectionProperty(PDOException::class, 'code');
        $reflection->setValue($previous, '23505');

        $e = new UniqueConstraintViolationException(
            'pgsql',
            'insert into "users" ("master_slug") values (?) -- users_master_slug_unique',
            ['ivan'],
            $previous,
        );
        $e->errorInfo = ['23505', null, null];

        $this->assertFalse(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    public function test_rejects_non_23505_with_slug_name(): void
    {
        $e = $this->makeUniqueViolation(
            'ERROR: something about users_master_slug_unique without sqlstate',
            'XX000',
        );

        $this->assertFalse(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    public function test_rejects_plain_query_exception(): void
    {
        $e = new QueryException('pgsql', 'select 1', [], new PDOException('boom'));

        $this->assertFalse(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
    }

    // ── Retry loop (direct) ───────────────────────────────

    public function test_run_retries_only_on_master_slug_unique_and_regenerates_slug(): void
    {
        User::factory()->create(['master_slug' => 'taken-slug', 'phone' => '79000000101']);

        $calls = 0;
        $slugs = [];

        $user = app(MasterSlugCreateRetry::class)->run(
            function () use (&$calls): string {
                $calls++;

                return $calls === 1 ? 'taken-slug' : 'free-slug';
            },
            function (string $slug) use (&$slugs): User {
                $slugs[] = $slug;

                return User::create([
                    'name' => 'Retry Master',
                    'phone' => '79000000102',
                    'is_master' => true,
                    'master_slug' => $slug,
                    'pdn_consent_at' => now(),
                    'pdn_consent_version' => 'v-test',
                ]);
            },
        );

        $this->assertSame(2, $calls);
        $this->assertSame(['taken-slug', 'free-slug'], $slugs);
        $this->assertSame('free-slug', $user->fresh()->master_slug);
        $this->assertSame(1, User::where('phone', '79000000102')->count());
    }

    public function test_run_throws_after_three_attempts_without_creating_user(): void
    {
        User::factory()->create(['master_slug' => 'taken-slug', 'phone' => '79000000101']);

        $calls = 0;

        try {
            app(MasterSlugCreateRetry::class)->run(
                function () use (&$calls): string {
                    $calls++;

                    return 'taken-slug';
                },
                fn (string $slug): User => User::create([
                    'name' => 'Exhausted',
                    'phone' => '79000000103',
                    'is_master' => true,
                    'master_slug' => $slug,
                ]),
            );
            $this->fail('Expected QueryException after exhausting attempts');
        } catch (QueryException $e) {
            $this->assertTrue(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
        }

        $this->assertSame(MasterSlugCreateRetry::MAX_ATTEMPTS, $calls);
        $this->assertNull(User::where('phone', '79000000103')->first());
    }

    public function test_run_does_not_retry_phone_unique_violation(): void
    {
        User::factory()->create(['phone' => '79000000104', 'master_slug' => 'phone-owner']);

        $calls = 0;

        try {
            app(MasterSlugCreateRetry::class)->run(
                function () use (&$calls): string {
                    $calls++;

                    return 'fresh-slug-'.uniqid();
                },
                fn (string $slug): User => User::create([
                    'name' => 'Phone Race',
                    'phone' => '79000000104',
                    'is_master' => true,
                    'master_slug' => $slug,
                ]),
            );
            $this->fail('Expected phone unique violation');
        } catch (QueryException $e) {
            $this->assertFalse(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
        }

        $this->assertSame(1, $calls);
        $this->assertSame(1, User::where('phone', '79000000104')->count());
    }

    // ── VK path ───────────────────────────────────────────

    public function test_vk_registration_retries_on_master_slug_conflict(): void
    {
        User::factory()->create(['master_slug' => 'ivan-petrov', 'phone' => '79000000201']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        $this->mockSlugSequence('ivan-petrov', 'ivan-petrov-free');
        $this->mockVkOAuth();

        $this->withSession($this->vkPendingSession())
            ->get($this->vkCallbackUrl());

        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());

        $user = User::findByVkId('999001');
        $this->assertNotNull($user);
        $this->assertSame('ivan-petrov-free', $user->master_slug);
        $this->assertNotNull($user->workspace_id);
        $this->assertSame('11.08.2026', $user->pdn_consent_version);
    }

    public function test_vk_registration_exhausts_attempts_without_partial_user(): void
    {
        User::factory()->create(['master_slug' => 'ivan-petrov', 'phone' => '79000000201']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')
            ->times(MasterSlugCreateRetry::MAX_ATTEMPTS)
            ->andReturn('ivan-petrov');

        $this->mockVkOAuth();

        // QueryException extends RuntimeException — callback turns it into
        // a safe redirect; assert no partial rows and slug-specific error.
        $response = $this->withSession($this->vkPendingSession())
            ->get($this->vkCallbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'users_master_slug_unique',
            (string) session('error'),
        );

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($workspacesBefore, Workspace::count());
        $this->assertNull(User::findByVkId('999001'));
    }

    public function test_vk_phone_conflict_keeps_existing_lookup_without_slug_retry(): void
    {
        $existing = User::factory()->master()->create([
            'phone' => '79001234567',
            'vk_id' => 'other_vk_id',
            'master_slug' => 'other-slug',
        ]);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->never();

        $this->mockVkOAuth(userInfo: [
            'user_id' => '999001',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+7 900 123-45-67',
        ]);

        $response = $this->withSession($this->vkPendingSession())
            ->get($this->vkCallbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');

        $existing->refresh();
        $this->assertSame('other_vk_id', $existing->vk_id);
        $this->assertSame(1, User::where('phone', '79001234567')->count());
    }

    public function test_vk_race_on_phone_unique_still_finds_existing_user(): void
    {
        $raceSlug = 'race-vk-'.uniqid('', true);
        $racePhone = '7900'.random_int(1000000, 9999999);
        $raceVkId = 'vk_race_'.uniqid('', true);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->andReturnUsing(function () use ($raceSlug, $racePhone, $raceVkId): string {
            $this->insertMasterOnSeparateConnection([
                'phone' => $racePhone,
                'vk_id' => $raceVkId,
                'master_slug' => $raceSlug,
            ]);

            return 'brand-new-slug-'.uniqid('', true);
        });

        $this->mockVkOAuth(
            exchange: ['user_id' => $raceVkId],
            userInfo: [
                'user_id' => $raceVkId,
                'first_name' => 'Иван',
                'last_name' => 'Петров',
                // normalizePhone strips non-digits → must equal $racePhone exactly.
                'phone' => '+7 '.substr($racePhone, 1, 3).' '.substr($racePhone, 4, 3).'-'.substr($racePhone, 7, 2).'-'.substr($racePhone, 9, 2),
            ],
        );

        $this->withSession($this->vkPendingSession())
            ->get($this->vkCallbackUrl());

        $this->assertSame(1, User::where('phone', $racePhone)->count());
        $found = User::where('phone', $racePhone)->firstOrFail();
        $this->assertSame($raceVkId, $found->vk_id);
        $this->assertSame(1, User::where('vk_id', $raceVkId)->count());
        $this->assertTrue(Auth::check());
        $this->assertSame($found->id, Auth::id());
        $this->assertSame(
            0,
            User::where('master_slug', 'brand-new-slug')->count(),
        );
    }

    // ── MAX path ──────────────────────────────────────────

    public function test_max_registration_retries_on_master_slug_conflict(): void
    {
        User::factory()->create(['master_slug' => 'master-name', 'phone' => '79000000301']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::MAX_CONSENT_PENDING.'max_uid_1', 'consent-99', 3600);
        Cache::put(CacheKeys::MAX_CHAT_TOKEN.'max_uid_1', 'login-token-max', 3600);

        $this->mockSlugSequence('master-name', 'master-name-free');
        $this->mockMaxApi();

        $this->postMaxContact('max_uid_1', '79000000302', 'Мастер', 'Имя');

        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());

        $user = User::where('max_id', 'max_uid_1')->firstOrFail();
        $this->assertSame('master-name-free', $user->master_slug);
        $this->assertNotNull($user->workspace_id);
        // Consent read once before the loop — not lost to the failed attempt.
        $this->assertSame('consent-99', $user->pdn_consent_version);
        $this->assertNull(Cache::get(CacheKeys::MAX_CONSENT_PENDING.'max_uid_1'));
    }

    public function test_max_registration_exhausts_attempts_without_partial_user(): void
    {
        User::factory()->create(['master_slug' => 'master-name', 'phone' => '79000000301']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::MAX_CHAT_TOKEN.'max_uid_1', 'login-token-max', 3600);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')
            ->times(MasterSlugCreateRetry::MAX_ATTEMPTS)
            ->andReturn('master-name');

        $this->mockMaxApi();

        // Webhook swallows Throwable → 200; assert DB state only.
        $this->postMaxContact('max_uid_1', '79000000302', 'Мастер', 'Имя')->assertOk();

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($workspacesBefore, Workspace::count());
        $this->assertNull(User::where('max_id', 'max_uid_1')->first());
    }

    public function test_max_phone_conflict_keeps_existing_lookup_without_slug_retry(): void
    {
        $existing = User::factory()->master()->create([
            'phone' => '79001234567',
            'max_id' => 'other-max',
            'master_slug' => 'other-max-slug',
        ]);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->never();

        $this->mockMaxApi();
        Cache::put(CacheKeys::MAX_CHAT_TOKEN.'max_uid_1', 'login-token-max', 3600);

        $this->postMaxContact('max_uid_1', '79001234567', 'Мастер', 'Имя')->assertOk();

        $existing->refresh();
        $this->assertSame('other-max', $existing->max_id);
        $this->assertSame(1, User::where('phone', '79001234567')->count());
        $this->assertSame(0, User::where('max_id', 'max_uid_1')->count());
    }

    public function test_max_race_on_phone_unique_still_finds_existing_user(): void
    {
        $raceSlug = 'race-max-'.uniqid('', true);
        $racePhone = '7900'.random_int(1000000, 9999999);
        $raceMaxId = 'max_race_'.uniqid('', true);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->andReturnUsing(function () use ($raceSlug, $racePhone, $raceMaxId): string {
            $this->insertMasterOnSeparateConnection([
                'phone' => $racePhone,
                'max_id' => $raceMaxId,
                'master_slug' => $raceSlug,
            ]);

            return 'max-brand-new-slug-'.uniqid('', true);
        });

        $this->mockMaxApi();
        Cache::put(CacheKeys::MAX_CHAT_TOKEN.$raceMaxId, 'login-token-max', 3600);

        $this->postMaxContact($raceMaxId, $racePhone, 'Мастер', 'Имя')->assertOk();

        $this->assertSame(1, User::where('phone', $racePhone)->count());
        $found = User::where('phone', $racePhone)->firstOrFail();
        $this->assertSame($raceMaxId, $found->max_id);
        $this->assertSame(1, User::where('max_id', $raceMaxId)->count());
    }

    // ── Telegram path ─────────────────────────────────────

    public function test_telegram_registration_retries_on_master_slug_conflict(): void
    {
        User::factory()->create(['master_slug' => 'username-master', 'phone' => '79000000401']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::TG_CONSENT_PENDING.'555001', 'tg-consent-77', 3600);
        Cache::put(CacheKeys::TG_CHAT_TOKEN.'555001', 'login-token-tg', 3600);

        $this->ensureTelegraphBot();
        $this->mockSlugSequence('username-master', 'username-master-free');

        $this->postTelegramContact('555001', '79000000402', 'Иван', 'Петров')->assertOk();

        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());

        $user = User::where('telegram_id', '555001')->firstOrFail();
        $this->assertSame('username-master-free', $user->master_slug);
        $this->assertNotNull($user->workspace_id);
        $this->assertSame('tg-consent-77', $user->pdn_consent_version);
        $this->assertNull(Cache::get(CacheKeys::TG_CONSENT_PENDING.'555001'));
    }

    public function test_telegram_registration_exhausts_attempts_without_partial_user(): void
    {
        User::factory()->create(['master_slug' => 'username-master', 'phone' => '79000000401']);

        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::TG_CHAT_TOKEN.'555001', 'login-token-tg', 3600);

        $this->ensureTelegraphBot();

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')
            ->times(MasterSlugCreateRetry::MAX_ATTEMPTS)
            ->andReturn('username-master');

        $this->postTelegramContact('555001', '79000000402', 'Иван', 'Петров')->assertOk();

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($workspacesBefore, Workspace::count());
        $this->assertNull(User::where('telegram_id', '555001')->first());
    }

    public function test_telegram_phone_conflict_keeps_existing_lookup_without_slug_retry(): void
    {
        $existing = User::factory()->master()->create([
            'phone' => '79001234567',
            'telegram_id' => 'other-tg',
            'master_slug' => 'other-tg-slug',
        ]);

        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->never();

        $this->ensureTelegraphBot();
        Cache::put(CacheKeys::TG_CHAT_TOKEN.'555001', 'login-token-tg', 3600);

        $this->postTelegramContact('555001', '79001234567', 'Иван', 'Петров')->assertOk();

        $existing->refresh();
        $this->assertSame('other-tg', $existing->telegram_id);
        $this->assertSame(1, User::where('phone', '79001234567')->count());
        $this->assertSame(0, User::where('telegram_id', '555001')->count());
    }

    // ── Address: happy-path registration ──────────────────

    public function test_vk_happy_path_registration_still_works(): void
    {
        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        $this->mockVkOAuth();
        $this->withSession($this->vkPendingSession())
            ->get($this->vkCallbackUrl());

        $user = User::findByVkId('999001');
        $this->assertNotNull($user);
        $this->assertNotNull($user->master_slug);
        $this->assertNotNull($user->workspace_id);
        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());
    }

    public function test_max_happy_path_registration_still_works(): void
    {
        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::MAX_CHAT_TOKEN.'max_uid_9', 'login-token-max', 3600);
        $this->mockMaxApi();

        $this->postMaxContact('max_uid_9', '79000000501', 'Мастер', 'MAX')->assertOk();

        $user = User::where('max_id', 'max_uid_9')->firstOrFail();
        $this->assertNotNull($user->master_slug);
        $this->assertNotNull($user->workspace_id);
        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());
    }

    public function test_telegram_happy_path_registration_still_works(): void
    {
        $usersBefore = User::count();
        $workspacesBefore = Workspace::count();

        Cache::put(CacheKeys::TG_CHAT_TOKEN.'555009', 'login-token-tg', 3600);
        $this->ensureTelegraphBot();

        $this->postTelegramContact('555009', '79000000502', 'Иван', 'TG')->assertOk();

        $user = User::where('telegram_id', '555009')->firstOrFail();
        $this->assertNotNull($user->master_slug);
        $this->assertNotNull($user->workspace_id);
        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame($workspacesBefore + 1, Workspace::count());
    }

    // ── Helpers ───────────────────────────────────────────

    private function makeUniqueViolation(string $message, string $sqlState): UniqueConstraintViolationException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, null, null];
        // PDOException::getCode() may be int; SQLSTATE lives in errorInfo[0].
        if ($sqlState !== '' && ctype_digit($sqlState)) {
            $reflection = new \ReflectionProperty(PDOException::class, 'code');
            $reflection->setValue($previous, $sqlState);
        }

        $e = new UniqueConstraintViolationException('pgsql', 'insert into "users" values (...)', [], $previous);
        $e->errorInfo = [$sqlState, null, null];

        return $e;
    }

    private function mockSlugSequence(string ...$slugs): void
    {
        $slug = $this->mock(SlugService::class);
        $slug->shouldReceive('generate')->andReturnValues($slugs);
    }

    /**
     * INSERT мастера на отдельном PDO-соединении с автокоммитом:
     * основное соединение теста остаётся в обёртке RefreshDatabase и видит
     * строку только после коммита (READ COMMITTED), как при реальной гонке.
     * Короткий lock_timeout — не зависать на чужих блокировках test DB.
     *
     * @param  array{phone: string, master_slug: string, vk_id?: string, max_id?: string, telegram_id?: string}  $attrs
     */
    private function insertMasterOnSeparateConnection(array $attrs): void
    {
        $pdo = $this->openSeparatePdo(config('database.connections.pgsql'));

        $id = (string) Str::uuid();
        $now = now()->toDateTimeString();

        $pdo->prepare(
            'INSERT INTO users (id, name, email, password, phone, vk_id, max_id, telegram_id, is_master, '
            .'master_slug, role, telegram_notifications, max_notifications, vk_notifications, soft_deposit, '
            .'deposit_timeout, deposit_percent, slot_interval, is_super_admin, is_blocked, is_service_provider, '
            .'admin_can_see_finance, autofill_enabled, created_at, updated_at) '
            .'VALUES (?, ?, NULL, ?, ?, ?, ?, ?, true, ?, \'owner\', false, false, false, false, '
            .'15, 30, 30, false, false, false, false, false, ?, ?)',
        )->execute([
            $id,
            'Race Master',
            'password',
            $attrs['phone'],
            $attrs['vk_id'] ?? null,
            $attrs['max_id'] ?? null,
            $attrs['telegram_id'] ?? null,
            $attrs['master_slug'],
            $now,
            $now,
        ]);

        $this->raceUserIds[] = $id;
        $pdo = null;
    }

    /**
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $config
     */
    private function deleteUsersOnSeparateConnection(array $userIds, array $config): void
    {
        $pdo = $this->openSeparatePdo($config);

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")
            ->execute($userIds);

        $pdo = null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function openSeparatePdo(array $config): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 5432,
            $config['database'] ?? 'vovremya_test',
        );

        $pdo = new \PDO($dsn, $config['username'] ?? 'postgres', $config['password'] ?? 'root', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        // Не ждать дольше 2с, если test DB залочена другим процессом.
        $pdo->exec("SET lock_timeout = '2s'");

        return $pdo;
    }

    private function mockVkOAuth(?array $exchange = null, ?array $userInfo = null): void
    {
        $defaultExchange = [
            'access_token' => 'at_123',
            'user_id' => '999001',
        ];
        $defaultInfo = [
            'user_id' => '999001',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+7 900 123-45-67',
        ];

        $mock = $this->mock(VkIdOAuthService::class);
        $mock->shouldReceive('generateCodeVerifier')->andReturn('test_verifier');
        $mock->shouldReceive('codeChallenge')->with('test_verifier')->andReturn('test_challenge');
        $mock->shouldReceive('authorizationUrl')->andReturn('https://id.vk.com/authorize?mock=1');
        $mock->shouldReceive('exchangeCode')->andReturn(array_merge($defaultExchange, $exchange ?? []));
        $mock->shouldReceive('userInfo')->andReturn(array_merge($defaultInfo, $userInfo ?? []));
    }

    private function vkPendingSession(string $state = 'expected_state_abc'): array
    {
        return [
            'vk_auth_state' => $state,
            'vk_auth_code_verifier' => 'test_verifier',
            'vk_auth_legal_version' => '11.08.2026',
            'vk_auth_issued_at' => time(),
        ];
    }

    private function vkCallbackUrl(string $code = 'auth_code', string $state = 'expected_state_abc', string $deviceId = 'dev_1'): string
    {
        return route('auth.vk.callback', [
            'code' => $code,
            'state' => $state,
            'device_id' => $deviceId,
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

    private function postMaxContact(
        string $senderId,
        string $phone,
        string $firstName,
        string $lastName,
    ): TestResponse {
        $vcf = "BEGIN:VCARD\nVERSION:3.0\nN:{$lastName};{$firstName};;;\nFN:{$firstName} {$lastName}\nTEL;TYPE=CELL:{$phone}\nEND:VCARD";
        $vcfNormalized = rtrim(preg_replace("/\r\n|\r|\n/", "\r\n", $vcf))."\r\n";

        $payload = [
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
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        return $this->postJson('/webhooks/max', $payload, [
            'X-Max-Bot-Api-Secret' => 'max-webhook-secret',
        ]);
    }

    private function ensureTelegraphBot(): void
    {
        if (! TelegraphBot::query()->where('token', 'fake-tg-token')->exists()) {
            TelegraphBot::create([
                'token' => 'fake-tg-token',
                'name' => 'n10-test-bot',
            ]);
        }
    }

    private function postTelegramContact(
        string $chatId,
        string $phone,
        string $firstName,
        string $lastName,
    ): TestResponse {
        return $this->postJson('/webhooks/telegram', [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, PHP_INT_MAX),
                'date' => time(),
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'from' => [
                    'id' => (int) $chatId,
                    'is_bot' => false,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'username' => 'username_master',
                ],
                'contact' => [
                    'phone_number' => $phone,
                    'user_id' => (int) $chatId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'tg-webhook-secret',
        ]);
    }
}
