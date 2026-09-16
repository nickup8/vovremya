<?php

namespace App\Http\Middleware;

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

        if (! $originalAdmin || ! $originalAdmin->is_super_admin) {
            abort(403, 'Нет активной сессии подмены.');
        }

        return $next($request);
    }
}
