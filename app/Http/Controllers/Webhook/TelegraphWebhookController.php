<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TelegraphWebhookController extends Controller
{
    public function handle(Request $request, string $token): Response
    {
        $bot = TelegraphBot::where('token', $token)->first();

        if (! $bot) {
            Log::warning('Telegraph Webhook: bot not found', [
                'remote_addr' => $request->ip(),
            ]);

            abort(404, 'Bot not found');
        }

        Log::info('Telegraph Webhook received', [
            'bot_id' => $bot->id,
            'bot_name' => $bot->name,
            'remote_addr' => $request->ip(),
        ]);

        /** @var class-string<WebhookHandler> $handlerClass */
        $handlerClass = config('telegraph.webhook.handler');

        /** @var WebhookHandler $handler */
        $handler = app($handlerClass);

        try {
            $handler->handle($request, $bot);
        } catch (\Throwable $e) {
            Log::error('[TG] Telegraph webhook processing failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return response()->noContent();
    }
}
