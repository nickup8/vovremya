<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TelegramAuthController extends Controller
{
    private const CACHE_PREFIX = 'tg_auth:';

    /**
     * Страница выбора способа входа.
     */
    public function showChoose()
    {
        return Inertia::render('auth/choose', [
            'telegramBotName' => config('services.telegram.bot_name'),
        ]);
    }

    /**
     * Генерация токена авторизации для Deep Linking.
     *
     * Фронтенд запрашивает этот эндпоинт, получает токен,
     * формирует ссылку https://t.me/BOT?start=auth_TOKEN
     * и запускает поллинг checkAuthStatus().
     */
    public function generateLoginToken(): JsonResponse
    {
        $token = 'auth_' . Str::uuid();

        // Сохраняем в Cache со статусом pending
        Cache::put(
            self::CACHE_PREFIX . $token,
            ['status' => 'pending'],
            config('booking.token_ttl'),
        );

        return response()->json(['token' => $token]);
    }

    /**
     * Проверка статуса токена (поллинг с фронтенда).
     *
     * Бот обновляет статус на 'authenticated' после получения контакта.
     * Как только статус изменён — авторизуем пользователя и возвращаем success.
     */
    public function checkAuthStatus(string $token): JsonResponse
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        $data = Cache::get($cacheKey);

        if (! $data) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Токен авторизации истёк. Попробуйте снова.',
            ]);
        }

        if ($data['status'] === 'pending') {
            return response()->json(['status' => 'pending']);
        }

        if ($data['status'] === 'authenticated') {
            // Токен одноразовый — удаляем
            Cache::forget($cacheKey);

            $userId = $data['user_id'] ?? null;
            if (! $userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ошибка авторизации. Попробуйте снова.',
                ]);
            }

            $user = User::find($userId);
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Пользователь не найден.',
                ]);
            }

            auth()->login($user);

            return response()->json(['status' => 'success']);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Неизвестный статус токена.',
        ]);
    }

    /**
     * Выход пользователя.
     */
    public function logout(Request $request)
    {
        auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
