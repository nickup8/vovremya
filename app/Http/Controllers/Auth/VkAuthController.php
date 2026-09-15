<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\VkIdOAuthService;
use App\Services\SlugService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VkAuthController extends Controller
{
    public function __construct(
        private VkIdOAuthService $oauth,
        private SlugService $slug,
        private WorkspaceService $workspace,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(16));
        $verifier = $this->oauth->generateCodeVerifier();
        $challenge = $this->oauth->codeChallenge($verifier);

        $request->session()->put([
            'vk_auth_state' => $state,
            'vk_auth_code_verifier' => $verifier,
            'vk_auth_legal_version' => config('legal.version'),
            'vk_auth_issued_at' => time(),
        ]);

        $url = $this->oauth->authorizationUrl($state, $challenge);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $deviceId = $request->query('device_id');

        if (! $code || ! $state || ! $deviceId) {
            $this->clearPendingAuth($request);

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        // ── Session state validation ──

        $expectedState = $request->session()->get('vk_auth_state');
        $codeVerifier = $request->session()->get('vk_auth_code_verifier');
        $legalVersion = $request->session()->get('vk_auth_legal_version');
        $issuedAt = $request->session()->get('vk_auth_issued_at');

        if (! $expectedState || ! $codeVerifier || ! $issuedAt) {
            $this->clearPendingAuth($request);

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        if (! hash_equals($expectedState, $state)) {
            $this->clearPendingAuth($request);

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        if (time() - $issuedAt > 600) {
            $this->clearPendingAuth($request);

            return $this->safeErrorRedirect($request, 'Сессия авторизации истекла. Попробуйте снова.');
        }

        // ── One-time: consume session before VK API call ──

        $request->session()->forget([
            'vk_auth_state',
            'vk_auth_code_verifier',
            'vk_auth_legal_version',
            'vk_auth_issued_at',
        ]);

        // ── Token exchange ──

        try {
            $tokenResponse = $this->oauth->exchangeCode($code, $deviceId, $state, $codeVerifier);
        } catch (\RuntimeException $e) {
            Log::warning('[VkAuth] token exchange failed', ['status' => $e->getMessage()]);

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        // ── Token state validation (if present) ──

        if (! empty($tokenResponse['state']) && ! hash_equals($state, $tokenResponse['state'])) {
            Log::warning('[VkAuth] token state mismatch');

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        // ── User info ──

        try {
            $info = $this->oauth->userInfo($tokenResponse['access_token']);
        } catch (\RuntimeException $e) {
            Log::warning('[VkAuth] user info failed', ['status' => $e->getMessage()]);

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        $vkUserId = (string) $info['user_id'];
        $firstName = $info['first_name'] ?? null;
        $lastName = $info['last_name'] ?? null;
        $phone = $this->normalizePhone($info['phone'] ?? null);

        // ── Identity cross-check ──

        if (! empty($tokenResponse['user_id']) && (string) $tokenResponse['user_id'] !== $vkUserId) {
            Log::warning('[VkAuth] token user_id != info user_id');

            return $this->safeErrorRedirect($request, 'Не удалось войти через VK. Попробуйте ещё раз.');
        }

        // ── Reconcile ──

        try {
            $user = $this->reconcileUser($vkUserId, $phone, $firstName, $lastName, $legalVersion);
        } catch (\RuntimeException $e) {
            return $this->safeErrorRedirect($request, $e->getMessage());
        }

        // ── Login ──

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/admin/calendar');
    }

    // ── Private helpers ──

    private function reconcileUser(
        string $vkUserId,
        ?string $phone,
        ?string $firstName,
        ?string $lastName,
        ?string $legalVersion,
    ): User {
        $name = $this->buildName($firstName, $lastName);

        // A. Find by VK ID first
        $user = User::findByVkId($vkUserId);
        if ($user) {
            if ($name !== '') {
                $user->name = $name;
            }
            if ($legalVersion && $user->pdn_consent_version !== $legalVersion) {
                $user->pdn_consent_at = now();
                $user->pdn_consent_version = $legalVersion;
            }
            if ($user->isDirty()) {
                $user->save();
            }

            return $user;
        }

        // B. Fallback by phone — phone required
        if (! $phone) {
            throw new \RuntimeException('VK не передал номер телефона. Разрешите доступ к номеру и попробуйте снова.');
        }

        $user = User::where('phone', $phone)->first();

        // C. Found by phone
        if ($user) {
            if ($user->vk_id !== null && $user->vk_id !== $vkUserId) {
                throw new \RuntimeException('Этот номер телефона уже привязан к другому VK-аккаунту.');
            }

            if ($user->vk_id === null) {
                $user->vk_id = $vkUserId;
            }

            if ($name !== '') {
                $user->name = $name;
            }
            if ($legalVersion && $user->pdn_consent_version !== $legalVersion) {
                $user->pdn_consent_at = now();
                $user->pdn_consent_version = $legalVersion;
            }
            if ($user->isDirty()) {
                $user->save();
            }

            return $user;
        }

        // D. New user — try create, catch race
        try {
            return DB::transaction(function () use ($vkUserId, $phone, $name, $legalVersion, $firstName, $lastName) {
                $slug = $this->slug->generate(null, $firstName, $lastName);

                $user = User::create([
                    'name' => $name !== '' ? $name : 'Мастер',
                    'phone' => $phone,
                    'vk_id' => $vkUserId,
                    'is_master' => true,
                    'master_slug' => $slug,
                    'pdn_consent_at' => now(),
                    'pdn_consent_version' => $legalVersion,
                ]);

                $this->workspace->createForUser($user);

                return $user;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Race: try find the concurrently-created user
            $user = User::findByVkId($vkUserId);
            if ($user) {
                return $user;
            }

            $user = User::where('phone', $phone)->first();
            if ($user && ($user->vk_id === null || $user->vk_id === $vkUserId)) {
                return $user;
            }

            throw $e;
        }
    }

    private function clearPendingAuth(Request $request): void
    {
        $request->session()->forget([
            'vk_auth_state',
            'vk_auth_code_verifier',
            'vk_auth_legal_version',
            'vk_auth_issued_at',
        ]);
    }

    private function safeErrorRedirect(Request $request, string $message): RedirectResponse
    {
        return redirect()->route('auth.choose')->with('error', $message);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        return $cleaned !== '' ? $cleaned : null;
    }

    private function buildName(?string $firstName, ?string $lastName): string
    {
        return trim(($firstName ?? '').' '.($lastName ?? ''));
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        return str_contains($e->getMessage(), '23505')
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
