<?php

namespace App\Http\Middleware;

use App\Enums\PlatformPermission;
use App\Models\Client;
use App\Models\User;
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

        $authUser = null;
        $authClient = null;

        $tariffName = 'Free';
        $tariffCode = 'start';
        $tariffLimits = null;

        if ($user instanceof User) {
            if ($user->workspace) {
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

                $tariffName = $tariffData['name'];
                $tariffCode = $tariffData['code'];
                $total = $tariffData['total'];

                $tariffLimits = [
                    'total' => $total === PHP_INT_MAX ? null : $total,
                    'used' => $tariffData['used'],
                ];
            }

            $authUser = [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'tariff_name' => $tariffName,
                'tariff_code' => $tariffCode,
                'can_manage_team' => $user->role->canManageTeam(),
                'can_manage_billing' => $user->role->canManageBilling(),
            ];
        } elseif ($user instanceof Client) {
            $authClient = [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
            ];
        }

        return [
            ...parent::share($request),
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $authUser,
                'client' => $authClient,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
            'tariff_limits' => $tariffLimits,
            'platformAdmin' => $this->platformAdminProps($user),
        ];
    }

    private function platformAdminProps($user): array
    {
        if (! $user instanceof User) {
            return ['isRoot' => false, 'permissions' => []];
        }

        if ($user->is_super_admin) {
            return [
                'isRoot' => true,
                'permissions' => array_map(fn (PlatformPermission $p) => $p->value, PlatformPermission::cases()),
            ];
        }

        $access = $user->platformAdminAccess;

        if ($access && $access->is_active) {
            return [
                'isRoot' => false,
                'permissions' => $access->permissions ?? [],
            ];
        }

        return ['isRoot' => false, 'permissions' => []];
    }
}
