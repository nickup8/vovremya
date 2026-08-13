<?php

namespace Tests\Feature;

use App\Services\Auth\MaxInitDataVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaxInitDataVerifierTest extends TestCase
{
    use RefreshDatabase;

    private string $testToken = 'test-bot-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.max.bot_token' => $this->testToken]);
        config(['booking.initdata_ttl' => 3600]);
    }

    /**
     * Генерирует валидную initData строку (как это делает MAX Mini App).
     */
    private function generateInitData(array $extraParams = [], ?string $token = null, ?int $authDate = null): string
    {
        $token = $token ?? $this->testToken;
        $authDate = $authDate ?? time();

        // Используем ASCII-only имена: json_encode экранирует кириллицу (\u0422),
        // что ломает HMAC из-за несовпадения байт при urldecode→json_decode→json_encode.
        // Реальный MAX шлёт UTF-8, но для теста достаточно ASCII.
        $params = array_merge([
            'auth_date' => (string) $authDate,
            'user' => json_encode(['id' => 8039166, 'first_name' => 'Test', 'last_name' => 'Tester']),
            'start_param' => 'book_42',
        ], $extraParams);

        // Убираем null-значения (模拟 отсутствие параметра)
        $params = array_filter($params, fn ($v) => $v !== null);

        // urlencode значения
        $encoded = [];

        foreach ($params as $key => $value) {
            $encoded[$key] = urlencode((string) $value);
        }

        // Сортировка по ключу
        ksort($encoded);

        // Собрать пары для подписи (без hash)
        $pairsForSign = [];

        foreach ($encoded as $key => $value) {
            $pairsForSign[] = $key.'='.$value;
        }

        $launchParams = implode("\n", $pairsForSign);

        // Вычислить hash
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $hash = hash_hmac('sha256', $launchParams, $secretKey, false);

        // Собрать финальную строку (не urlencoded, как в реальном initData)
        $pairsFinal = [];

        foreach ($params as $key => $value) {
            $pairsFinal[] = $key.'='.urlencode((string) $value);
        }

        $pairsFinal[] = 'hash='.$hash;

        return implode('&', $pairsFinal);
    }

    // ═══════════════════════════════════════════
    // ВЕРИФИКАТОР
    // ═══════════════════════════════════════════

    public function test_valid_init_data_returns_result(): void
    {
        $initData = $this->generateInitData();

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNotNull($result);
        $this->assertSame('8039166', $result->userId);
        $this->assertSame('book_42', $result->startParam);
        $this->assertNull($result->chatId);
        $this->assertArrayHasKey('user', $result->raw);
    }

    public function test_valid_init_data_with_chat(): void
    {
        $initData = $this->generateInitData([
            'chat' => json_encode(['id' => 340011760, 'type' => 'dialog']),
        ]);

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNotNull($result);
        $this->assertSame('8039166', $result->userId);
        $this->assertSame('340011760', $result->chatId);
    }

    public function test_start_param_is_null_when_absent(): void
    {
        $initData = $this->generateInitData([
            'start_param' => null,
        ]);

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNotNull($result);
        $this->assertNull($result->startParam);
    }

    public function test_invalid_signature_returns_null(): void
    {
        $initData = $this->generateInitData();
        // Подменяем hash
        $initData = preg_replace('/hash=[a-f0-9]+/', 'hash=0000000000000000000000000000000000000000000000000000000000000000', $initData);

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNull($result);
    }

    public function test_expired_auth_date_returns_null(): void
    {
        $oldAuthDate = time() - 7200; // 2 часа назад, TTL = 3600
        $initData = $this->generateInitData(authDate: $oldAuthDate);

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNull($result);
    }

    public function test_missing_hash_returns_null(): void
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 123]),
        ];
        $encoded = [];

        foreach ($params as $key => $value) {
            $encoded[$key] = urlencode($value);
        }

        $pairs = [];

        foreach ($encoded as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $initData = implode('&', $pairs);

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNull($result);
    }

    public function test_empty_init_data_returns_null(): void
    {
        $result = app(MaxInitDataVerifier::class)->verify('');

        $this->assertNull($result);
    }

    public function test_empty_token_returns_null(): void
    {
        config(['services.max.bot_token' => '']);

        $initData = $this->generateInitData();

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════
    // API РОУТ
    // ═══════════════════════════════════════════

    public function test_ping_with_valid_init_data_returns_200(): void
    {
        $initData = $this->generateInitData();

        $response = $this->getJson('/api/miniapp/ping', [
            'X-Max-Init-Data' => $initData,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'user_id' => '8039166',
                'start_param' => 'book_42',
            ]);
    }

    public function test_ping_with_invalid_signature_returns_401(): void
    {
        $initData = $this->generateInitData();
        $initData = preg_replace('/hash=[a-f0-9]+/', 'hash=0000000000000000000000000000000000000000000000000000000000000000', $initData);

        $response = $this->getJson('/api/miniapp/ping', [
            'X-Max-Init-Data' => $initData,
        ]);

        $response->assertStatus(401);
    }

    public function test_ping_with_expired_auth_returns_401(): void
    {
        $initData = $this->generateInitData(authDate: time() - 7200);

        $response = $this->getJson('/api/miniapp/ping', [
            'X-Max-Init-Data' => $initData,
        ]);

        $response->assertStatus(401);
    }

    public function test_ping_without_header_returns_401(): void
    {
        $response = $this->getJson('/api/miniapp/ping');

        $response->assertStatus(401);
    }

    public function test_ping_via_init_data_query_param_returns_200(): void
    {
        $initData = $this->generateInitData();

        $response = $this->getJson('/api/miniapp/ping?init_data='.urlencode($initData));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'user_id' => '8039166',
            ]);
    }

    public function test_ping_without_start_param_returns_null(): void
    {
        $initData = $this->generateInitData([
            'user' => json_encode(['id' => 999, 'first_name' => 'X']),
            'start_param' => null,
        ]);

        $response = $this->getJson('/api/miniapp/ping', [
            'X-Max-Init-Data' => $initData,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'user_id' => '999',
                'start_param' => null,
            ]);
    }
}
