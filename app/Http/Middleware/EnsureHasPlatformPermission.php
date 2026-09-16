<?php

namespace App\Http\Middleware;

use App\Enums\PlatformPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasPlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        // ROOT bypass
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Validate permission exists in enum
        $validPermission = PlatformPermission::tryFrom($permission);

        if (! $validPermission) {
            abort(403);
        }

        $access = $user->platformAdminAccess;

        if (! $access || ! $access->is_active || ! $access->hasPermission($validPermission)) {
            abort(403);
        }

        return $next($request);
    }
}
