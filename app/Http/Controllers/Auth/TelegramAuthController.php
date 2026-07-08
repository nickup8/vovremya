<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TelegramAuthController extends Controller
{
    /**
     * Страница выбора способа входа.
     */
    public function showChoose()
    {
        return Inertia::render('auth/choose', [
            'telegramBotName' => config('services.telegram.bot_name', env('TELEGRAM_BOT_NAME')),
        ]);
    }

    /**
     * Callback-обработчик Telegram Login Widget.
     *
     * Telegram перенаправляет пользователя на data-auth-url с GET-параметрами:
     *   id, first_name, last_name, username, photo_url, auth_date, hash
     *
     * Проверка подлинности: HMAC-SHA256 по схеме Telegram Bot API.
     * См. https://core.telegram.org/widgets/login#checking-authorization
     */
    public function callback(Request $request): RedirectResponse
    {
        // Все параметры, переданные виджетом
        $id         = $request->query('id');
        $firstName  = $request->query('first_name', '');
        $lastName   = $request->query('last_name', '');
        $username   = $request->query('username', '');
        $photoUrl   = $request->query('photo_url', '');
        $authDate   = $request->query('auth_date');
        $hash       = $request->query('hash');

        // 1. Проверяем обязательные поля
        if (! $id || ! $authDate || ! $hash) {
            Log::warning('Telegram auth callback: отсутствуют обязательные параметры', $request->query());

            return redirect()->route('auth.choose')
                ->with('error', 'Авторизация через Telegram не удалась. Попробуйте снова.');
        }

        // 2. Проверяем时效ность auth_date (макс. 5 минут)
        if (time() - (int) $authDate > 300) {
            Log::warning('Telegram auth callback: auth_date устарел', [
                'auth_date' => $authDate,
                'diff' => time() - (int) $authDate,
            ]);

            return redirect()->route('auth.choose')
                ->with('error', 'Срок действия авторизации истёк. Попробуйте снова.');
        }

        // 3. Формируем строку данных для проверки (сортировка ключей алфавитно)
        $dataCheckString = http_build_query([
            'auth_date'  => $authDate,
            'first_name' => $firstName,
            'id'         => $id,
            'last_name'  => $lastName,
            'photo_url'  => $photoUrl,
            'username'   => $username,
        ], '', "\n", PHP_QUERY_RFC3986);

        // 4. Вычисляем ожидаемый HMAC-SHA256
        $botToken = config('services.telegram.bot_token');
        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // 5. Строгая проверка подписи (timing-safe)
        if (! hash_equals($expectedHash, $hash)) {
            Log::warning('Telegram auth callback: невалидная подпись', [
                'id' => $id,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('auth.choose')
                ->with('error', 'Подпись Telegram недействительна. Попробуйте снова.');
        }

        // 6. Подпись валидна — ищем или создаём пользователя
        $telegramId = (string) $id;

        $user = User::where('telegram_id', $telegramId)->first();

        if (! $user) {
            // Генерируем slug из имени пользователя
            $baseName = trim($firstName . ' ' . $lastName);
            if ($baseName === '') {
                $baseName = $username !== '' ? '@'.$username : 'Мастер '.$telegramId;
            }

            $slug = \Illuminate\Support\Str::slug($baseName);
            $originalSlug = $slug;

            $counter = 1;
            while (User::where('master_slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $user = User::create([
                'name'         => $baseName,
                'telegram_id'  => $telegramId,
                'is_master'    => true,
                'master_slug'  => $slug,
                'specialty'    => null,
                'address'      => null,
                'avatar_url'   => $photoUrl ?: null,
            ]);

            Log::info('Telegram auth: создан новый мастер', [
                'user_id'     => $user->id,
                'telegram_id' => $telegramId,
                'name'        => $baseName,
            ]);
        } else {
            // Обновляем имя/аватар, если изменились
            $fullName = trim($firstName . ' ' . $lastName);
            $updates = [];

            if ($fullName !== '' && $user->name !== $fullName) {
                $updates['name'] = $fullName;
            }
            if ($photoUrl !== '' && $user->avatar_url !== $photoUrl) {
                $updates['avatar_url'] = $photoUrl;
            }

            if (! empty($updates)) {
                $user->update($updates);
            }

            Log::info('Telegram auth: вход существующего мастера', [
                'user_id'     => $user->id,
                'telegram_id' => $telegramId,
            ]);
        }

        // 7. Авторизуем и редиректим
        auth()->login($user);

        return redirect()->route('admin.calendar');
    }

    /**
     * Выход пользователя.
     */
    public function logout(Request $request): RedirectResponse
    {
        auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
