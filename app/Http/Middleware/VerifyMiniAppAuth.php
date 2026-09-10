<?php

namespace App\Http\Middleware;

use App\Services\Auth\MaxInitDataVerifier;
use App\Services\Auth\VkLaunchParamsVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMiniAppAuth
{
    public function __construct(
        private MaxInitDataVerifier $maxVerifier,
        private VkLaunchParamsVerifier $vkVerifier,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $hasMaxChannel = $request->hasHeader('X-Max-Init-Data') || $request->has('init_data');
        $hasVkChannel = $request->hasHeader('Authorization');

        if (! $hasMaxChannel && ! $hasVkChannel) {
            abort(401, 'missing_credentials');
        }

        if ($hasMaxChannel && $hasVkChannel) {
            abort(401, 'ambiguous_credentials');
        }

        if ($hasMaxChannel) {
            return $this->handleMax($request, $next);
        }

        return $this->handleVk($request, $next);
    }

    private function handleMax(Request $request, \Closure $next): Response
    {
        $raw = $request->header('X-Max-Init-Data')
            ?? $request->input('init_data')
            ?? '';

        $result = $this->maxVerifier->verify($raw);

        if ($result === null) {
            abort(401, 'invalid_init_data');
        }

        $request->attributes->set('max_init', $result);

        return $next($request);
    }

    private function handleVk(Request $request, \Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if ($header === '' || ! str_starts_with(strtolower($header), 'bearer ')) {
            abort(401, 'invalid_vk_launch_params');
        }

        $token = substr($header, 7);

        if ($token === '') {
            abort(401, 'invalid_vk_launch_params');
        }

        $result = $this->vkVerifier->verify($token);

        if ($result === null) {
            abort(401, 'invalid_vk_launch_params');
        }

        $request->attributes->set('vk_launch', $result);

        return $next($request);
    }
}
