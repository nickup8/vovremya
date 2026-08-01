<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Забаненный (is_blocked) User (guard web) — разлогинить и увести на страницу входа.
     * ВАЖНО: guard 'client' НЕ трогаем — Client.is_blocked это локальный бан мастером.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user && $user->is_blocked) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Ваш аккаунт заблокирован. Обратитесь в поддержку.';

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
