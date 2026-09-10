<?php

namespace App\Http\Middleware;

use App\Services\Auth\VkLaunchParamsVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyVkLaunchParams
{
    public function handle(Request $request, \Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if ($header === '' || ! str_starts_with(strtolower($header), 'bearer ')) {
            abort(401, 'invalid_vk_launch_params');
        }

        $token = substr($header, 7);

        if ($token === '') {
            abort(401, 'invalid_vk_launch_params');
        }

        $result = app(VkLaunchParamsVerifier::class)->verify($token);

        if ($result === null) {
            abort(401, 'invalid_vk_launch_params');
        }

        $request->attributes->set('vk_launch', $result);

        return $next($request);
    }
}
