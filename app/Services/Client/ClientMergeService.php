<?php

namespace App\Services\Client;

use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\ProviderLinkConflictException;
use App\Models\Client;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ClientMergeService
{
    /**
     * @throws AmbiguousClientMatchException
     * @throws ProviderLinkConflictException
     */
    public function findOrCreateByPhone(
        string $masterId,
        string $phone,
        string $telegramId = '',
        ?string $name = null,
        ?string $workspaceId = null,
    ): Client {
        $normalized = Phone::normalize($phone);

        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        $resolvedName = $name !== null && trim($name) !== ''
            ? $name
            : __('bot.fallback.client_name');

        return $this->withPhoneLock($masterId, $normalized, function () use (
            $masterId,
            $normalized,
            $telegramId,
            $name,
            $resolvedName,
            $workspaceId,
        ) {
            $client = $this->findUniqueByPhone($masterId, $normalized, $workspaceId);

            if ($client === null) {
                try {
                    $this->assertProviderIdFree('telegram', $telegramId, null);

                    return Client::create([
                        'user_id' => $masterId,
                        'phone' => $normalized,
                        'name' => $resolvedName,
                        'telegram_id' => $telegramId !== '' ? $telegramId : null,
                        'workspace_id' => $workspaceId,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Параллельный запрос успел создать карточку — переиспользуем её.
                    $client = $this->findUniqueByPhone($masterId, $normalized, $workspaceId);

                    if ($client === null) {
                        throw new \RuntimeException('Client could not be created or reloaded after unique conflict.');
                    }
                }
            }

            return $this->applyExistingClientUpdates($client, $telegramId, $name);
        });
    }

    /**
     * Ищет клиента мастера/workspace по нормализованному телефону,
     * включая legacy-записи с +7/8 и маской.
     *
     * @throws AmbiguousClientMatchException
     */
    public function findByPhone(
        string $masterId,
        string $phone,
        ?string $workspaceId = null,
    ): ?Client {
        $normalized = Phone::normalize($phone);

        if ($normalized === null) {
            return null;
        }

        return $this->findUniqueByPhone($masterId, $normalized, $workspaceId);
    }

    public function linkProvider(Client $client, string $provider, string $providerId): Client
    {
        $field = match ($provider) {
            'telegram' => 'telegram_id',
            'max' => 'max_id',
            'vk' => 'vk_id',
            default => null,
        };

        if ($field === null || $providerId === '') {
            return $client;
        }

        $existing = $client->{$field};

        if ($existing === null || $existing === '') {
            $this->assertProviderIdFree($provider, $providerId, $client->id);
            $client->update([$field => $providerId]);

            return $client;
        }

        if ((string) $existing === (string) $providerId) {
            return $client;
        }

        // Не перезаписываем чужую/старую привязку — только явный конфликт.
        throw ProviderLinkConflictException::forProvider($provider);
    }

    public function updateTelegramId(Client $client, string $telegramId): Client
    {
        return $this->linkProvider($client, 'telegram', $telegramId);
    }

    public function updateMaxId(Client $client, string $maxId): Client
    {
        return $this->linkProvider($client, 'max', $maxId);
    }

    public function updateMaxChatId(Client $client, string $maxChatId): Client
    {
        $client->update(['max_chat_id' => $maxChatId]);

        return $client;
    }

    /**
     * PostgreSQL: сериализуем find-or-create по (master, phone), чтобы
     * конкурентные запросы не создали две карточки.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withPhoneLock(string $masterId, string $normalizedPhone, \Closure $callback): mixed
    {
        $run = fn (): mixed => $callback();

        if (DB::getDriverName() !== 'pgsql') {
            return $run();
        }

        return DB::transaction(function () use ($masterId, $normalizedPhone, $run) {
            $lockKey = sprintf('%u', crc32("client-phone:{$masterId}:{$normalizedPhone}"));
            DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$lockKey]);

            return $run();
        });
    }

    /**
     * @throws AmbiguousClientMatchException
     */
    private function findUniqueByPhone(string $masterId, string $normalizedPhone, ?string $workspaceId): ?Client
    {
        $clients = $this->queryInScope($masterId, $normalizedPhone, $workspaceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($clients->count() <= 1) {
            return $clients->first();
        }

        // Детерминированные правила выбора при нескольких legacy-дублях.
        $exact = $clients->filter(fn (Client $c) => (string) $c->phone === $normalizedPhone);
        if ($exact->count() === 1) {
            return $exact->first();
        }

        $personal = $clients->filter(
            fn (Client $c) => (string) $c->user_id === (string) $masterId && $c->workspace_id === null
        );
        if ($personal->count() === 1) {
            return $personal->first();
        }

        throw AmbiguousClientMatchException::forPhone($normalizedPhone);
    }

    private function queryInScope(string $masterId, string $normalizedPhone, ?string $workspaceId): Builder
    {
        $variants = Phone::storageVariants($normalizedPhone);
        $digitForms = Phone::digitForms($normalizedPhone);

        return Client::query()
            ->where(function (Builder $scope) use ($masterId, $workspaceId) {
                $scope->where('user_id', $masterId);

                if ($workspaceId !== null && $workspaceId !== '') {
                    $scope->orWhere('workspace_id', $workspaceId);
                }
            })
            ->where(function (Builder $phoneScope) use ($variants, $digitForms) {
                $phoneScope->whereIn('phone', $variants);

                foreach ($digitForms as $form) {
                    // Legacy: phone с маской "+7 (900) 123-45-67", "8 (900)..." и т.п.
                    $phoneScope->orWhereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$form]);
                }
            });
    }

    private function applyExistingClientUpdates(
        Client $client,
        string $telegramId,
        ?string $incomingName,
    ): Client {
        $updates = [];

        if ($this->isUsableIncomingName($incomingName) && $this->isPlaceholder($client->name)) {
            $updates['name'] = $incomingName;
        }

        if ($telegramId !== '') {
            $this->assertProviderIdFree('telegram', $telegramId, $client->id);

            $existingTelegram = $client->telegram_id;

            if ($existingTelegram === null || $existingTelegram === '') {
                $updates['telegram_id'] = $telegramId;
            } elseif ((string) $existingTelegram !== (string) $telegramId) {
                throw ProviderLinkConflictException::forProvider('telegram');
            }
        }

        if ($updates !== []) {
            $client->update($updates);
        }

        return $client;
    }

    private function assertProviderIdFree(string $provider, string $providerId, ?string $exceptClientId): void
    {
        if ($providerId === '') {
            return;
        }

        $field = match ($provider) {
            'telegram' => 'telegram_id',
            'max' => 'max_id',
            'vk' => 'vk_id',
            default => null,
        };

        if ($field === null) {
            return;
        }

        $query = Client::query()->where($field, $providerId);

        if ($exceptClientId !== null) {
            $query->where('id', '!=', $exceptClientId);
        }

        if ($query->exists()) {
            throw ProviderLinkConflictException::forProvider($provider);
        }
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
        if (str_starts_with($name, $fallback.' ')) {
            $suffix = trim(substr($name, strlen($fallback)));
            $digits = preg_replace('/[^0-9]/', '', $suffix);

            // Phone-like: at least 7 digits, and non-digit chars are only formatting
            if (strlen((string) $digits) >= 7 && preg_match('/^[0-9\s+\-()]+$/', $suffix) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isUsableIncomingName(?string $name): bool
    {
        return $name !== null && trim($name) !== '' && ! $this->isPlaceholder($name);
    }
}
