<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\VkIdOAuthService;
use App\Services\SlugService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class VkAuthTest extends TestCase
{
    use RefreshDatabase;

    private function mockOAuth(?array $exchange = null, ?array $userInfo = null): void
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

    private function pendingSession(?string $state = 'expected_state_abc'): array
    {
        return [
            'vk_auth_state' => $state,
            'vk_auth_code_verifier' => 'test_verifier',
            'vk_auth_legal_version' => '11.08.2026',
            'vk_auth_issued_at' => time(),
        ];
    }

    private function callbackUrl(string $code = 'auth_code', string $state = 'expected_state_abc', string $deviceId = 'dev_1'): string
    {
        return route('auth.vk.callback', [
            'code' => $code,
            'state' => $state,
            'device_id' => $deviceId,
        ]);
    }

    // ── START ──────────────────────────────────────────────

    public function test_start_stores_session_keys(): void
    {
        $this->mockOAuth();

        $this->post(route('auth.vk.start'));

        $this->assertNotNull(session('vk_auth_state'));
        $this->assertSame('test_verifier', session('vk_auth_code_verifier'));
        $this->assertSame('11.08.2026', session('vk_auth_legal_version'));
        $this->assertNotNull(session('vk_auth_issued_at'));
    }

    public function test_start_redirects_to_vk_authorize_url(): void
    {
        $this->mockOAuth();

        $response = $this->post(route('auth.vk.start'));

        $response->assertRedirect('https://id.vk.com/authorize?mock=1');
    }

    // ── CALLBACK VALIDATION ────────────────────────────────

    public function test_missing_code_rejected(): void
    {
        $this->mockOAuth();

        $response = $this->withSession($this->pendingSession())
            ->get(route('auth.vk.callback', ['state' => 'expected_state_abc', 'device_id' => 'dev_1']));

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
        $this->assertNull(session('vk_auth_state'));
    }

    public function test_missing_state_rejected(): void
    {
        $this->mockOAuth();

        $response = $this->withSession($this->pendingSession())
            ->get(route('auth.vk.callback', ['code' => 'abc', 'device_id' => 'dev_1']));

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    public function test_missing_device_id_rejected(): void
    {
        $this->mockOAuth();

        $response = $this->withSession($this->pendingSession())
            ->get(route('auth.vk.callback', ['code' => 'abc', 'state' => 'expected_state_abc']));

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    public function test_state_mismatch_rejected(): void
    {
        $this->mockOAuth();

        $response = $this->withSession($this->pendingSession('correct_state'))
            ->get($this->callbackUrl(state: 'wrong_state'));

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    public function test_expired_state_rejected(): void
    {
        $this->mockOAuth();

        $session = $this->pendingSession();
        $session['vk_auth_issued_at'] = time() - 601;

        $response = $this->withSession($session)
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    public function test_replay_rejected_after_first_callback_consumed(): void
    {
        $this->mockOAuth();
        User::factory()->create(['vk_id' => '999001', 'is_master' => true, 'master_slug' => 'ivan']);

        // First callback — succeeds
        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        // Second callback — session consumed, should fail
        $response = $this->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    // ── TOKEN SECURITY ─────────────────────────────────────

    public function test_token_response_state_mismatch_rejected(): void
    {
        $this->mockOAuth(exchange: ['state' => 'different_state']);

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    public function test_token_user_id_mismatch_rejected(): void
    {
        $this->mockOAuth(exchange: ['user_id' => '888000']);

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    // ── EXISTING USER ──────────────────────────────────────

    public function test_existing_user_by_vk_id_logs_in_without_phone(): void
    {
        $user = User::factory()->create([
            'vk_id' => '999001',
            'is_master' => true,
            'master_slug' => 'ivan',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_existing_user_by_vk_id_redirects_to_calendar(): void
    {
        User::factory()->create([
            'vk_id' => '999001',
            'is_master' => true,
            'master_slug' => 'ivan',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect('/admin/calendar');
    }

    // ── PHONE LINK ─────────────────────────────────────────

    public function test_existing_user_by_phone_with_null_vk_id_gets_vk_id_attached(): void
    {
        $user = User::factory()->master()->create([
            'phone' => '79001234567',
            'vk_id' => null,
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+7 900 123-45-67']);

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());

        $user->refresh();
        $this->assertSame('999001', $user->vk_id);
    }

    public function test_user_by_phone_with_different_vk_id_rejected(): void
    {
        User::factory()->master()->create([
            'phone' => '79001234567',
            'vk_id' => 'other_vk_id',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+7 900 123-45-67']);

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');

        // vk_id unchanged
        $user = User::firstWhere('phone', '79001234567');
        $this->assertSame('other_vk_id', $user->vk_id);
    }

    // ── NEW USER ───────────────────────────────────────────

    public function test_new_user_with_phone_created(): void
    {
        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+7 900 123-45-67']);

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $user = User::findByVkId('999001');

        $this->assertNotNull($user);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame('79001234567', $user->phone);
        $this->assertTrue($user->is_master);
        $this->assertNotNull($user->master_slug);
        $this->assertNotNull($user->pdn_consent_at);
        $this->assertSame('11.08.2026', $user->pdn_consent_version);

        $this->assertNotNull($user->workspace_id);
        $this->assertNotNull(Workspace::find($user->workspace_id));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_new_user_without_phone_not_created(): void
    {
        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');

        $this->assertNull(User::findByVkId('999001'));
        $this->assertFalse(Auth::check());
    }

    // ── PDN ────────────────────────────────────────────────

    public function test_existing_user_gets_pdn_version_updated(): void
    {
        $user = User::factory()->create([
            'vk_id' => '999001',
            'is_master' => true,
            'master_slug' => 'ivan',
            'pdn_consent_at' => now()->subMonth(),
            'pdn_consent_version' => '01.01.2025',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $user->refresh();
        $this->assertSame('11.08.2026', $user->pdn_consent_version);
        $this->assertTrue($user->pdn_consent_at->isToday());
    }

    // ── ERROR HANDLING ─────────────────────────────────────

    public function test_exchange_runtime_exception_redirects_safely(): void
    {
        $mock = $this->mock(VkIdOAuthService::class);
        $mock->shouldReceive('generateCodeVerifier')->andReturn('v');
        $mock->shouldReceive('codeChallenge')->andReturn('c');
        $mock->shouldReceive('authorizationUrl')->andReturn('https://id.vk.com/authorize');
        $mock->shouldReceive('exchangeCode')->andThrow(new \RuntimeException('VK API returned 400'));

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');

        $body = $response->getContent();
        $this->assertStringNotContainsString('400', $body);
    }

    public function test_user_info_runtime_exception_redirects_safely(): void
    {
        $mock = $this->mock(VkIdOAuthService::class);
        $mock->shouldReceive('generateCodeVerifier')->andReturn('v');
        $mock->shouldReceive('codeChallenge')->andReturn('c');
        $mock->shouldReceive('authorizationUrl')->andReturn('https://id.vk.com/authorize');
        $mock->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'secret_token',
            'user_id' => '999001',
        ]);
        $mock->shouldReceive('userInfo')->andThrow(new \RuntimeException('HTTP 401'));

        $response = $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $response->assertRedirect(route('auth.choose'));
        $response->assertSessionHas('error');
    }

    // ── SESSION ────────────────────────────────────────────

    public function test_successful_flow_authenticates_user(): void
    {
        User::factory()->create([
            'vk_id' => '999001',
            'is_master' => true,
            'master_slug' => 'ivan',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $this->assertFalse(Auth::check());

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $this->assertTrue(Auth::check());
    }

    public function test_pending_vk_session_cleared_after_callback(): void
    {
        User::factory()->create([
            'vk_id' => '999001',
            'is_master' => true,
            'master_slug' => 'ivan',
        ]);

        $this->mockOAuth(userInfo: ['user_id' => '999001', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null]);

        $this->withSession($this->pendingSession())
            ->get($this->callbackUrl());

        $this->assertNull(session('vk_auth_state'));
        $this->assertNull(session('vk_auth_code_verifier'));
        $this->assertNull(session('vk_auth_legal_version'));
        $this->assertNull(session('vk_auth_issued_at'));
    }

    // ── ROUTE TEST ─────────────────────────────────────────

    public function test_routes_are_registered(): void
    {
        $this->assertSame('/auth/vk/start', route('auth.vk.start', [], false));
        $this->assertSame('/auth/vk/callback', route('auth.vk.callback', [], false));
    }
}
