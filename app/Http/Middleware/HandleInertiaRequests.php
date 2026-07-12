<?php

namespace App\Http\Middleware;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $user = $request->user();

        if ($user && $user->isBlocked()) {
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

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'tariff' => $user->tariff,
                    'tariff_name' => match ($user->tariff) {
                        'pro' => 'Профи',
                        'studio' => 'Студия',
                        default => 'Free',
                    },
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
