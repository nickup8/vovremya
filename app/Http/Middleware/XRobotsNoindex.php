<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class XRobotsNoindex
{
    private const EXCLUDED = [
        'offer',
        'privacy',
        'book',
        'book/*',
        'login',
        'logout',
        'auth',
        'auth/*',
        'switch-to-client',
        'client-mode',
        'client-mode/*',
        'client',
        'client/*',
        'admin',
        'admin/*',
        'admin-root',
        'admin-root/*',
        'r',
        'r/*',
        'max-app',
        'vk-app',
        'api',
        'api/*',
        'dev',
        'dev/*',
        'max/diag',
        'max/diag/*',
        'max/webhook',
        'webhooks/*',
        'telegraph/*',
        'broadcasting/*',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is(...self::EXCLUDED)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
