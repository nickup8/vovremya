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
        $client = Client::firstOrCreate(
            ['user_id' => $masterId, 'phone' => $phone],
            [
                'name' => $name,
                'telegram_id' => $telegramId ?: null,
            ]
        );

        if (! $client->wasRecentlyCreated) {
            $updates = ['name' => $name];

            if ($telegramId !== '' && empty($client->telegram_id)) {
                $updates['telegram_id'] = $telegramId;
            }

            $client->update($updates);
        }

        return $client;
    }

    public function linkProvider(Client $client, string $provider, string $providerId): Client
    {
        $field = match ($provider) {
            'telegram' => 'telegram_id',
            'max' => 'max_id',
            default => null,
        };

        if ($field && $providerId !== '') {
            $client->update([$field => $providerId]);
        }

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
