<?php

namespace App\Services\Client;

use App\Models\Client;

class ClientMergeService
{
    public function findOrCreateByPhone(
        int $masterId,
        string $phone,
        string $telegramId = '',
        string $name = 'Клиент',
    ): Client {
        $client = Client::updateOrCreate(
            ['user_id' => $masterId, 'phone' => $phone],
            [
                'telegram_id' => $telegramId,
                'name' => $name,
            ]
        );

        return $client;
    }

    public function updateTelegramId(Client $client, string $telegramId): Client
    {
        $client->update(['telegram_id' => $telegramId]);

        return $client;
    }

    public function updateMaxId(Client $client, string $maxId): Client
    {
        $client->update(['max_id' => $maxId]);

        return $client;
    }
}
