<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VkWebhookController
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.vk.secret');

        if ($secret === '') {
            Log::critical('[VK] webhook secret not configured');
            abort(500, 'VK webhook secret not configured');
        }

        $provided = $request->input('secret');

        if (! is_string($provided) || ! hash_equals($secret, $provided)) {
            Log::warning('[VK] webhook secret mismatch', [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Invalid VK webhook secret');
        }

        $type = $request->input('type');

        if ($type === 'confirmation') {
            $token = config('services.vk.confirmation_token');

            if (empty($token)) {
                Log::critical('[VK] confirmation_token not configured');
                abort(500, 'VK confirmation_token not configured');
            }

            return response($token);
        }

        Log::debug('[VK] webhook event received', [
            'type' => $type,
            'group_id' => $request->input('group_id'),
        ]);

        return response('ok');
    }
}
