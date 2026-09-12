<?php

namespace App\Services\Client;

use App\Models\Client;

class ClientMergeService
{
    public function findOrCreateByPhone(
        string $masterId,
        string $phone,
        string $telegramId = '',
        ?string $name = null,
        ?string $workspaceId = null,
    ): Client {
        $resolvedName = $name !== null && trim($name) !== ''
            ? $name
            : __('bot.fallback.client_name');

        $client = Client::firstOrCreate(
            ['user_id' => $masterId, 'phone' => $phone],
            [
                'name' => $resolvedName,
                'telegram_id' => $telegramId ?: null,
                'workspace_id' => $workspaceId,
            ]
        );

        if (! $client->wasRecentlyCreated) {
            $updates = [];

            if ($this->isUsableIncomingName($name) && $this->isPlaceholder($client->name)) {
                $updates['name'] = $name;
            }

            if ($telegramId !== '' && empty($client->telegram_id)) {
                $updates['telegram_id'] = $telegramId;
            }

            if ($updates !== []) {
                $client->update($updates);
            }
        }

        return $client;
    }

    private function isPlaceholder(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return true;
        }

        $fallback = __('bot.fallback.client_name');

        if ($name === $fallback) {
            return true;
        }

        // "Клиент 79001112233", "Клиент +7 900 111-22-33", etc.
        if (str_starts_with($name, $fallback . ' ')) {
            $suffix = trim(substr($name, strlen($fallback)));
            $digits = preg_replace('/[^0-9]/', '', $suffix);

            // Phone-like: at least 7 digits, and non-digit chars are only formatting
            if (strlen($digits) >= 7 && preg_match('/^[0-9\s+\-()]+$/', $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isUsableIncomingName(?string $name): bool
    {
        return $name !== null && trim($name) !== '' && ! $this->isPlaceholder($name);
    }

    public function findByTelegramIdForMaster(string $telegramId, string $masterId): ?Client
    {
        return Client::byTelegramId($telegramId)
            ->where('user_id', $masterId)
            ->first();
    }

    public function findByMaxIdForMaster(string $maxId, string $masterId): ?Client
    {
        return Client::byMaxId($maxId)
            ->where('user_id', $masterId)
            ->first();
    }

    public function linkProvider(Client $client, string $provider, string $providerId): Client
    {
        $field = match ($provider) {
            'telegram' => 'telegram_id',
            'max' => 'max_id',
            'vk' => 'vk_id',
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

    public function updateMaxChatId(Client $client, string $maxChatId): Client
    {
        $client->update(['max_chat_id' => $maxChatId]);

        return $client;
    }
}
