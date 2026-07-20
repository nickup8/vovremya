<?php

namespace App\Http\Controllers;

use App\Webhooks\MaxWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DefStudio\Telegraph\Models\TelegraphBot;

class WebhookController extends Controller
{
    public function handleTelegram(Request $request): JsonResponse
    {
        $this->verifySignature('telegram', $request);

        try {
            app(\App\Webhooks\TelegramWebhookHandler::class)
                ->handle($request, TelegraphBot::firstOrFail());
        } catch (\Throwable $e) {
            Log::error('[TG] webhook processing failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Кастомный эндпоинт для обхода Route Model Binding пакета Telegraph.
     * Ищет бота вручную и делегирует обработку TelegramWebhookHandler.
     */
    public function handleBypass(Request $request)
    {
        $secret = config('services.telegram.secret_token');

        if (! empty($secret)) {
            $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

            if ($provided === null || ! hash_equals($secret, $provided)) {
                Log::warning('Bypass webhook: invalid secret token', [
                    'ip' => $request->ip(),
                ]);
                abort(403, 'Invalid secret token');
            }
        }

        $bot = TelegraphBot::first();

        if (! $bot) {
            return response('Bot not found', 404);
        }

        app(\App\Webhooks\TelegramWebhookHandler::class)->handle($request, $bot);

        return response('OK', 200);
    }

    public function handleMax(Request $request): JsonResponse
    {
        $this->verifySignature('max', $request);

        $payload = $request->all();

        Log::info('[MAX] webhook received', [
            'update_type' => $payload['update_type'] ?? null,
            'chat_id' => $payload['chat_id'] ?? null,
        ]);

        try {
            app(MaxWebhookHandler::class)->handle($payload);
        } catch (\Throwable $e) {
            Log::error('[MAX] webhook processing failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function handleVk(Request $request): mixed
    {
        $payload = $request->all();
        $type = $payload['type'] ?? '';
        $secret = $payload['secret'] ?? null;

        if ($type === 'confirmation') {
            Log::info('[VK] confirmation request received');

            return response(config('services.vk.confirmation_token'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $expectedSecret = config('services.vk.secret');
        if ($expectedSecret && $secret !== $expectedSecret) {
            Log::warning('[VK] invalid secret', [
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        Log::info('[VK] webhook received', [
            'type' => $type,
            'group_id' => $payload['group_id'] ?? null,
        ]);

        return response('ok', 200);
    }

    private function verifySignature(string $provider, Request $request): void
    {
        $secretKey = match ($provider) {
            'telegram' => 'services.telegram.secret_token',
            'max' => 'services.max.secret_token',
        };

        $secret = config($secretKey);

        if (empty($secret)) {
            if ($provider === 'max') {
                Log::info("[MAX] webhook secret not configured, skipping signature verification");

                return;
            }

            Log::critical("Webhook secret not configured for {$provider}");
            abort(500, 'Webhook secret not configured');
        }

        $headerName = match ($provider) {
            'telegram' => 'X-Telegram-Bot-Api-Secret-Token',
            'max' => 'X-Max-Signature',
        };

        $provided = $request->header($headerName);

        if ($provided === null || $provided === '') {
            Log::warning("Webhook signature missing for {$provider}", [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Webhook signature required');
        }

        if (! hash_equals((string) $secret, $provided)) {
            Log::warning("Webhook signature mismatch for {$provider}", [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Invalid webhook signature');
        }
    }
}
