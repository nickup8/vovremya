<?php

namespace App\Http\Middleware;

use App\Services\Billing\TariffLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        $tariffCode = null;
        $tariffName = 'Free';
        $tariffLimits = null;

        if ($user && $user->workspace) {
            $workspace = $user->workspace;
            $cacheKey = "tariff:{$workspace->id}";

            try {
                $tariffData = Cache::remember($cacheKey, 300, function () use ($workspace) {
                    $activeSubscription = $workspace->activeSubscription();
                    $limitService = app(TariffLimitService::class);

                    return [
                        'code' => $activeSubscription?->tariffPlan?->code ?? 'start',
                        'name' => $activeSubscription?->tariffPlan?->name ?? 'Старт',
                        'max_masters' => $activeSubscription?->tariffPlan?->max_masters ?? 0,
                        'total' => $limitService->getMonthlyLimit($workspace, $activeSubscription),
                        'used' => $limitService->getUsedCount($workspace, $activeSubscription),
                    ];
                });
            } catch (\Throwable) {
                // Fallback если Cache::tags() или другой драйвер не работает
                $activeSubscription = $workspace->activeSubscription();
                $limitService = app(TariffLimitService::class);

                $tariffData = [
                    'code' => $activeSubscription?->tariffPlan?->code ?? 'start',
                    'name' => $activeSubscription?->tariffPlan?->name ?? 'Старт',
                    'max_masters' => $activeSubscription?->tariffPlan?->max_masters ?? 0,
                    'total' => $limitService->getMonthlyLimit($workspace, $activeSubscription),
                    'used' => $limitService->getUsedCount($workspace, $activeSubscription),
                ];
            }

            $tariffCode = $tariffData['code'];
            $tariffName = $tariffData['name'];
            $maxMasters = $tariffData['max_masters'];
            $total = $tariffData['total'];

            $tariffLimits = [
                'total' => $total === PHP_INT_MAX ? null : $total,
                'used' => $tariffData['used'],
            ];
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
                    'can_manage_team' => $user->role->canManageTeam(),
                    'can_manage_billing' => $user->role->canManageBilling(),
                ] : null,
            ],
            'tariff_limits' => $tariffLimits,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
