<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гейт приватного доступа по фиче тарифа.
 * Использует canonical-механизм User::hasFeature (→ Workspace::hasFeature).
 *
 * ВАЖНО: применять только к приватным endpoints (управление/аналитика).
 * Публичный сбор attribution тарифом НЕ гейтится.
 */
class EnsureHasFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasFeature($feature)) {
            abort(403, 'Функция доступна на тарифе Профи.');
        }

        return $next($request);
    }
}
