<?php

namespace App\Http\Middleware;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Middleware;
use App\Services\Billing\TariffLimitService;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        $user = $request->user();

        if ($user && $user->isBlocked()) {
            Log::warning('Blocked user force-logged out', ['user_id' => $user->id, 'ip' => $request->ip()]);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsInertia()) {
                return inertia()->location(route('auth.choose'));
            }

            return new RedirectResponse(route('auth.choose'));
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        $tariffCode = null;
        $tariffName = 'Free';
        $tariffLimits = null;

        if ($user) {
            $activeSubscription = $user->workspace?->activeSubscription();
            $tariffCode = $activeSubscription?->tariffPlan?->code ?? 'start';
            $tariffName = $activeSubscription?->tariffPlan?->name ?? 'Старт';
            $maxMasters = $activeSubscription?->tariffPlan?->max_masters ?? 0;

            if ($user->workspace) {
                $limitService = app(TariffLimitService::class);
                $total = $limitService->getMonthlyLimit($user->workspace);
                $tariffLimits = [
                    'total' => $total === PHP_INT_MAX ? null : $total,
                    'used' => $limitService->getUsedCount($user->workspace),
                ];
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'tariff' => $tariffCode,
                    'avatar_url' => $user->avatar_url,
                    'tariff_name' => $tariffName,
                    'max_masters' => $maxMasters ?? 0,
                    'is_solo' => $user->isSolo(),
                    'can_manage_billing' => $user->role->canManageBilling(),
                ] : null,
            ],
            'tariff_limits' => $tariffLimits,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
