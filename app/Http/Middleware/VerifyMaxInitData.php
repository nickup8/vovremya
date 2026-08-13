<?php

namespace App\Http\Middleware;

use App\Services\Auth\MaxInitDataVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMaxInitData
{
    public function handle(Request $request, \Closure $next): Response
    {
        $initData = $request->header('X-Max-Init-Data')
            ?? $request->input('init_data')
            ?? '';

        $result = app(MaxInitDataVerifier::class)->verify($initData);

        if ($result === null) {
            abort(401, 'invalid_init_data');
        }

        $request->attributes->set('max_init', $result);

        return $next($request);
    }
}
