<?php

namespace App\Http\Middleware;

use App\Enums\PlatformPermission;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanLeaveImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $originalAdminId = $request->session()->get('original_admin_id');

        if (! $originalAdminId || ! Str::isUuid($originalAdminId)) {
            abort(403, 'Нет активной сессии подмены.');
        }

        $originalAdmin = User::find($originalAdminId);

        if (! $originalAdmin) {
            abort(403, 'Нет активной сессии подмены.');
        }

        // ROOT can always leave
        if ($originalAdmin->is_super_admin) {
            return $next($request);
        }

        // Limited admin with impersonation.use permission can leave
        $access = $originalAdmin->platformAdminAccess;

        if ($access && $access->is_active && $access->hasPermission(PlatformPermission::ImpersonationUse)) {
            return $next($request);
        }

        abort(403, 'Нет активной сессии подмены.');
    }
}
