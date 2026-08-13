<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;

class MaxInitDataVerifier
{
    /**
     * Верифицирует initData из MAX Mini App.
     * Возвращает DTO при валидной подписи и свежести, иначе null.
     */
    public function verify(string $initData): ?MaxInitDataResult
    {
        $initData = trim($initData);

        if ($initData === '') {
            return null;
        }

        $token = trim((string) config('services.max.bot_token'));

        if ($token === '') {
            Log::warning('[MAX] initData verify: bot_token не настроен');

            return null;
        }

        // 1. Разбить по '&' на пары key=value
        $pairs = explode('&', $initData);

        if (empty($pairs)) {
            return null;
        }

        $paramsRaw = [];
        $hash = null;
        $hashCount = 0;

        foreach ($pairs as $pair) {
            $eqPos = strpos($pair, '=');

            if ($eqPos === false) {
                continue;
            }

            $key = substr($pair, 0, $eqPos);
            $value = substr($pair, $eqPos + 1);

            // 2. Ключ 'hash' должен встречаться ровно один раз
            if ($key === 'hash') {
                $hash = $value;
                $hashCount++;

                continue;
            }

            $paramsRaw[$key] = $value;
        }

        // 3. Проверить что hash ровно один
        if ($hashCount !== 1 || $hash === null || $hash === '') {
            Log::warning('[MAX] initData verify: отсутствует или дублируется hash');

            return null;
        }

        // 4. Отсортировать по ключу (используя ЗАКОДИРОВАННЫЕ значения для launch_params)
        ksort($paramsRaw);

        // 5. Сформировать launch_params из закодированных пар
        $pairsSorted = [];

        foreach ($paramsRaw as $key => $value) {
            $pairsSorted[] = $key.'='.$value;
        }

        $launchParams = implode("\n", $pairsSorted);

        // 6. secret_key = HMAC-SHA256(BOT_TOKEN, 'WebAppData') в бинарном виде
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);

        // 7. expected_hash = HMAC-SHA256(launch_params, secret_key) в hex
        $expectedHash = hash_hmac('sha256', $launchParams, $secretKey, false);

        // 8. Сравнить хеши
        if (! hash_equals($expectedHash, $hash)) {
            Log::warning('[MAX] initData verify: подпись не совпадает');

            return null;
        }

        // Подпись валидна — декодируем значения для извлечения данных
        $params = [];

        foreach ($paramsRaw as $key => $value) {
            $params[$key] = urldecode($value);
        }

        // Проверка свежести
        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;

        if ($authDate <= 0) {
            Log::warning('[MAX] initData verify: auth_date отсутствует');

            return null;
        }

        $ttl = (int) config('booking.initdata_ttl', 3600);

        if ((time() - $authDate) > $ttl) {
            Log::warning('[MAX] initData verify: auth_date протух', [
                'age' => time() - $authDate,
                'ttl' => $ttl,
            ]);

            return null;
        }

        // Извлечь user (JSON-строка)
        $userId = '';
        $startParam = $params['start_param'] ?? null;
        $chatId = null;

        if (isset($params['user'])) {
            $userObj = json_decode($params['user'], true);

            if (is_array($userObj) && isset($userObj['id'])) {
                $userId = (string) $userObj['id'];
            }
        }

        if ($userId === '') {
            Log::warning('[MAX] initData verify: не удалось извлечь user.id');

            return null;
        }

        if (isset($params['chat'])) {
            $chatObj = json_decode($params['chat'], true);

            if (is_array($chatObj) && isset($chatObj['id'])) {
                $chatId = (string) $chatObj['id'];
            }
        }

        return new MaxInitDataResult(
            userId: $userId,
            authDate: $authDate,
            startParam: $startParam,
            chatId: $chatId,
            raw: $params,
        );
    }
}
