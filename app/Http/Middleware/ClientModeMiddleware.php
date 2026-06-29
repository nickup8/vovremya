<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ClientModeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('client_mode', fn () => session('is_client_mode', false));

        return $next($request);
    }
}
