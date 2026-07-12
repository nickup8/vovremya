<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use DefStudio\Telegraph\Controllers\WebhookController;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Диагностический контроллер для перехвата вебхука Telegraph.
 *
 * Логирует токен из URL, заголовки и результат поиска бота.
 * Помогает найти причину 404 при реальных запросах от Telegram.
 */
class TelegraphWebhookController extends Controller
{
    public function handle(Request $request, string $token): mixed
    {
        Log::info('=== Telegraph Webhook Debug ===', [
            'token_from_url' => $token,
            'token_length' => strlen($token),
            'token_hex' => bin2hex($token),
            'token_url_encoded' => urlencode($token),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'request_path' => $request->path(),
            'all_headers' => $request->headers->all(),
            'remote_addr' => $request->ip(),
            'content_type' => $request->header('content-type'),
            'content_length' => $request->header('content-length'),
        ]);

        // Проверяем, есть ли бот с таким токеном в БД
        $bot = TelegraphBot::where('token', $token)->first();

        if (! $bot) {
            Log::warning('Telegraph Webhook: бот НЕ найден', [
                'token' => $token,
                'all_bots' => TelegraphBot::select('id', 'name', 'token')->get()->toArray(),
            ]);

            return response('Bot not found', 404);
        }

        Log::info('Telegraph Webhook: бот найден', [
            'bot_id' => $bot->id,
            'bot_name' => $bot->name,
        ]);

        // Делегируем оригинальному контроллеру пакета
        $originalController = app(WebhookController::class);

        return $originalController->handle($request, $token);
    }
}
