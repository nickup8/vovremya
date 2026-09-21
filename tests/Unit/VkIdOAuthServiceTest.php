<?php

namespace Tests\Unit;

use App\Services\Auth\VkIdOAuthService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VkIdOAuthServiceTest extends TestCase
{
    private VkIdOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.vk_id.app_id', '54773434');
        config()->set('services.vk_id.redirect_uri', 'https://irsi-app.ru/auth/vk/callback');

        $this->service = new VkIdOAuthService();
    }

    public function test_code_verifier_has_sufficient_length(): void
    {
        $verifier = $this->service->generateCodeVerifier();

        $this->assertNotEmpty($verifier);
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $verifier);
    }

    public function test_s256_challenge_is_base64url_without_padding(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = $this->service->codeChallenge($verifier);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $challenge);
        $this->assertStringNotContainsString('=', $challenge);
        $this->assertStringNotContainsString('+', $challenge);
        $this->assertStringNotContainsString('/', $challenge);
    }

    public function test_authorization_url_contains_all_required_parameters(): void
    {
        $state = 'random_state_abc123';
        $challenge = 'test_challenge_value';

        $url = $this->service->authorizationUrl($state, $challenge);

        $parsed = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);

        $this->assertSame('https', $parsed['scheme']);
        $this->assertSame('id.vk.com', $parsed['host']);
        $this->assertSame('/authorize', $parsed['path']);

        $this->assertSame('54773434', $params['client_id']);
        $this->assertSame('https://irsi-app.ru/auth/vk/callback', $params['redirect_uri']);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame($state, $params['state']);
        $this->assertSame('phone', $params['scope']);
        $this->assertSame($challenge, $params['code_challenge']);
        $this->assertSame('s256', $params['code_challenge_method']);
    }

    public function test_exchange_code_sends_correct_request(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/auth*' => Http::response([
                'access_token' => 'test_access_token',
                'refresh_token' => 'test_refresh_token',
                'id_token' => 'test_id_token',
            ]),
        ]);

        $result = $this->service->exchangeCode(
            'test_code',
            'device_123',
            'state_abc',
            'verifier_xyz',
        );

        $this->assertSame('test_access_token', $result['access_token']);

        Http::assertSent(function ($request) {
            $url = $request->url();
            $body = $request->data();

            // Query string contains protocol params
            $queryOk = str_contains($url, 'grant_type=authorization_code')
                && str_contains($url, 'redirect_uri=' . urlencode('https://irsi-app.ru/auth/vk/callback'))
                && str_contains($url, 'client_id=54773434')
                && str_contains($url, 'code_verifier=verifier_xyz')
                && str_contains($url, 'state=state_abc')
                && str_contains($url, 'device_id=device_123');

            // POST body contains only authorization code
            $bodyOk = $body === ['code' => 'test_code'];

            // Authorization code must NOT be in query string
            $codeNotInQuery = ! str_contains(explode('?', $url)[1] ?? '', 'code=test_code');

            return $queryOk && $bodyOk && $codeNotInQuery;
        });
    }

    public function test_exchange_code_throws_on_http_error(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/auth*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        $this->service->exchangeCode('bad_code', 'device_1', 'state', 'verifier');
    }

    public function test_exchange_code_throws_when_no_access_token_in_response(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/auth*' => Http::response(['error' => 'some_error']),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no access_token');

        $this->service->exchangeCode('code', 'device_1', 'state', 'verifier');
    }

    public function test_exchange_code_error_does_not_expose_verifier(): void
    {
        $secretVerifier = 'super_secret_verifier_123456789012345678901234567890';

        Http::fake([
            '*id.vk.com/oauth2/auth*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        try {
            $this->service->exchangeCode('code', 'device_1', 'state', $secretVerifier);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($secretVerifier, $e->getMessage());
        }
    }

    public function test_user_info_sends_correct_request(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/user_info*' => Http::response([
                'user_id' => '999001',
                'first_name' => 'Иван',
                'last_name' => 'Петров',
                'phone' => '+79001234567',
            ]),
        ]);

        $result = $this->service->userInfo('my_access_token');

        $this->assertSame('999001', $result['user_id']);
        $this->assertSame('Иван', $result['first_name']);

        Http::assertSent(function ($request) {
            $url = $request->url();
            $body = $request->data();

            // Query string contains client_id
            $queryOk = str_contains($url, 'client_id=54773434');

            // POST body contains only access_token
            $bodyOk = $body === ['access_token' => 'my_access_token'];

            // access_token must NOT be in query string
            $tokenNotInQuery = ! str_contains($url, 'access_token=my_access_token');

            return $queryOk && $bodyOk && $tokenNotInQuery;
        });
    }

    public function test_user_info_unwraps_nested_user_key(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/user_info*' => Http::response([
                'user' => [
                    'user_id' => '12345',
                    'first_name' => 'Иван',
                    'last_name' => 'Иванов',
                    'phone' => '+79991234567',
                ],
            ]),
        ]);

        $result = $this->service->userInfo('my_access_token');

        $this->assertSame('12345', $result['user_id']);
        $this->assertSame('Иван', $result['first_name']);
        $this->assertSame('Иванов', $result['last_name']);
        $this->assertSame('+79991234567', $result['phone']);
    }

    public function test_user_info_throws_when_nested_user_missing_user_id(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/user_info*' => Http::response([
                'user' => [
                    'first_name' => 'Иван',
                ],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no user_id');

        $this->service->userInfo('my_access_token');
    }

    public function test_user_info_throws_on_http_error(): void
    {
        Http::fake([
            '*id.vk.com/oauth2/user_info*' => Http::response([], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->service->userInfo('expired_token');
    }

    public function test_user_info_error_does_not_expose_access_token(): void
    {
        $secretToken = 'super_secret_access_token_abcdef1234567890';

        Http::fake([
            '*id.vk.com/oauth2/user_info*' => Http::response([], 401),
        ]);

        try {
            $this->service->userInfo($secretToken);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($secretToken, $e->getMessage());
        }
    }
}
