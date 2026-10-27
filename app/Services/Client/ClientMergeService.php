<?php

namespace App\Services\Client;

use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\DuplicateClientPhoneException;
use App\Exceptions\ProviderLinkConflictException;
use App\Exceptions\StaleClientConfirmationException;
use App\Models\Client;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ClientMergeService
{
    /**
     * @throws AmbiguousClientMatchException
     * @throws ProviderLinkConflictException
     * @throws DuplicateClientPhoneException
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

        $ws = $this->normalizeWorkspaceId($workspaceId);

        return $this->withPhoneLock($masterId, $normalized, $ws, function () use (
            $masterId,
            $normalized,
            $telegramId,
            $name,
            $resolvedName,
            $ws,
        ) {
            $client = $this->findUniqueByPhone($masterId, $normalized, $ws);

            if ($client === null) {
                $client = $this->insertClient($masterId, $normalized, $resolvedName, $ws);
            }

            return $this->applyExistingClientUpdates($client, $telegramId, $name, $masterId, $ws);
        });
    }

    /**
     * Ищет клиента мастера/workspace по нормализованному телефону,
     * включая legacy-записи с +7/8/10-значной формой и маской.
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

        return $this->findUniqueByPhone($masterId, $normalized, $this->normalizeWorkspaceId($workspaceId));
    }

    /**
     * Ручное создание карточки из админки: та же область поиска и тот же
     * механизм блокировок, что у findOrCreateByPhone.
     *
     * @throws AmbiguousClientMatchException
     * @throws DuplicateClientPhoneException
     */
    public function createManualClient(
        string $masterId,
        string $phone,
        string $name,
        ?string $notes = null,
        ?string $workspaceId = null,
    ): Client {
        $normalized = Phone::normalize($phone);

        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        $ws = $this->normalizeWorkspaceId($workspaceId);

        return $this->withPhoneLock($masterId, $normalized, $ws, function () use ($masterId, $normalized, $name, $notes, $ws) {
            $existing = $this->findUniqueByPhone($masterId, $normalized, $ws);

            if ($existing !== null) {
                throw DuplicateClientPhoneException::create();
            }

            return $this->insertClient($masterId, $normalized, $name, $ws, $notes);
        });
    }

    /**
     * Ручное изменение телефона/данных: проверка дублей и запись выполняются
     * под блокировкой области, чтобы не создать дубль параллельно с подтверждением.
     *
     * @throws AmbiguousClientMatchException
     * @throws DuplicateClientPhoneException
     */
    public function updateManualClient(Client $client, string $phone, string $name, ?string $notes): Client
    {
        $normalized = Phone::normalize($phone);

        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        $masterId = (string) $client->user_id;
        $ws = $this->scopeWorkspaceId($client);
        $oldNormalized = Phone::normalize($client->phone);

        // Блокируются и старый, и новый номер (в едином порядке захвата):
        // подтверждение по старому номеру ждёт смену, а смена — подтверждение.
        $phones = $oldNormalized !== null && $oldNormalized !== $normalized
            ? [$oldNormalized, $normalized]
            : [$normalized];

        return $this->withPhoneLocks($phones, $masterId, $ws, function () use ($client, $masterId, $normalized, $name, $notes) {
            // Повтор чтения после ожидания блокировок: карточку могли
            // изменить, пока операция ждала старый/новый номер.
            $fresh = Client::query()
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return $client;
            }

            $others = $this->queryInScope($masterId, $normalized, $this->scopeWorkspaceId($fresh))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->filter(fn (Client $c) => (string) $c->id !== (string) $fresh->id);

            if ($others->count() > 1) {
                throw AmbiguousClientMatchException::forPhone($normalized);
            }

            if ($others->count() === 1) {
                throw DuplicateClientPhoneException::create();
            }

            $fresh->update([
                'phone' => $normalized,
                'name' => $name,
                'notes' => $notes,
            ]);

            $client->refresh();

            return $client;
        });
    }

    /**
     * Атомарная привязка мессенджера: актуальное состояние карточки читается
     * под блокировкой строки, проверка занятости provider_id в области —
     * под advisory-блокировкой, запись выполняется только при отсутствии
     * конфликта. Если передан $expectedPhone (подтверждение по номеру),
     * после ожидания блокировок дополнительно проверяется, что карточка
     * всё ещё соответствует подтверждённому номеру и разрешённой области.
     *
     * @throws ProviderLinkConflictException
     * @throws StaleClientConfirmationException
     */
    public function linkProvider(Client $client, string $provider, string $providerId, ?string $expectedPhone = null): Client
    {
        $field = $this->providerField($provider);

        if ($field === null || $providerId === '') {
            return $client;
        }

        $masterId = (string) $client->user_id;
        $ws = $this->scopeWorkspaceId($client);

        return $this->linkProviderScoped($client, $provider, $providerId, $masterId, $ws, $expectedPhone);
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
     * Вставка карточки внутри существующей транзакции блокировки.
     * На PostgreSQL уникальный конфликт ловится через savepoint (вложенная
     * транзакция), чтобы не продолжать работу в aborted-состоянии.
     *
     * @throws DuplicateClientPhoneException конфликт unique-индекса с карточкой
     *                                       вне разрешённой области: обработанный
     *                                       отказ без раскрытия/изменения чужой карточки
     */
    private function insertClient(
        string $masterId,
        string $normalizedPhone,
        string $name,
        ?string $workspaceId,
        ?string $notes = null,
    ): Client {
        try {
            return DB::transaction(fn (): Client => Client::create([
                'user_id' => $masterId,
                'phone' => $normalizedPhone,
                'name' => $name,
                'notes' => $notes,
                'workspace_id' => $workspaceId,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Карточка в ТЕКУЩЕЙ области с точным строковым совпадением
            // (тот же индекс) — переиспользуем: блокировку области мы держим,
            // поэтому её не могло появиться конкурентно.
            $client = $this->findUniqueByPhone($masterId, $normalizedPhone, $workspaceId);

            if ($client !== null) {
                return $client;
            }

            // Индекс занят карточкой другой области (например, того же
            // мастера в другом workspace): нельзя ни вернуть её наружу,
            // ни изменить. Отказ без раскрытия чужой карточки.
            throw DuplicateClientPhoneException::create();
        }
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

        // Несколько карточек в одной области — однозначный отказ.
        // Выбор одной из них (в т.ч. «канонической» или незаблокированной)
        // скрыл бы дубль от мастера и мог обойти блокировку.
        throw AmbiguousClientMatchException::forPhone($normalizedPhone);
    }

    /**
     * Область клиентской базы мастера/workspace:
     * - с workspace — все карточки workspace плюс legacy-карточки
     *   workspace_id=NULL самого мастера;
     * - без workspace (legacy-мастер) — только его персональные карточки;
     * - карточки того же user_id из другого workspace не захватываются.
     */
    /**
     * @return Builder<Client>
     */
    private function queryInScope(string $masterId, string $normalizedPhone, ?string $workspaceId): Builder
    {
        $variants = Phone::storageVariants($normalizedPhone);
        $digitForms = Phone::digitForms($normalizedPhone);

        return Client::query()
            ->where(function (Builder $scope) use ($masterId, $workspaceId) {
                if ($workspaceId !== null && $workspaceId !== '') {
                    $scope->where('workspace_id', $workspaceId)
                        ->orWhere(function (Builder $personal) use ($masterId) {
                            $personal->whereNull('workspace_id')
                                ->where('user_id', $masterId);
                        });
                } else {
                    $scope->where('user_id', $masterId)
                        ->whereNull('workspace_id');
                }
            })
            ->where(function (Builder $phoneScope) use ($variants, $digitForms) {
                $phoneScope->whereIn('phone', $variants);

                foreach ($digitForms as $form) {
                    // Legacy: phone с маской "+7 (900) 123-45-67", "8 (900)..."
                    // и т.п. «+» сохраняется: он значим для международных
                    // номеров и не должен стираться при сопоставлении.
                    $phoneScope->orWhereRaw("regexp_replace(phone, '[^0-9+]', '', 'g') = ?", [$form]);
                }
            });
    }

    /**
     * @throws ProviderLinkConflictException
     * @throws StaleClientConfirmationException
     */
    private function linkProviderScoped(
        Client $client,
        string $provider,
        string $providerId,
        string $masterId,
        ?string $workspaceId,
        ?string $expectedPhone = null,
    ): Client {
        $field = $this->providerField($provider);

        if ($field === null || $providerId === '') {
            return $client;
        }

        return $this->withProviderLock($masterId, $workspaceId, $provider, $providerId, function () use (
            $client,
            $field,
            $provider,
            $providerId,
            $masterId,
            $workspaceId,
            $expectedPhone,
        ) {
            $run = function () use ($client, $field, $provider, $providerId, $masterId, $workspaceId, $expectedPhone) {
                $fresh = Client::query()
                    ->whereKey($client->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null) {
                    throw ProviderLinkConflictException::forProvider($provider);
                }

                // После ожидания блокировок карточка должна всё ещё
                // соответствовать подтверждённому номеру и разрешенной
                // области — иначе мессенджер привязался бы к уже
                // изменённому телефону.
                if ($expectedPhone !== null
                    && (! Phone::sameNumber($fresh->phone, $expectedPhone)
                        || ! $this->queryInScopeById($masterId, $workspaceId)->whereKey($fresh->getKey())->exists())
                ) {
                    throw StaleClientConfirmationException::create();
                }

                $existing = $fresh->{$field};

                if ($existing !== null && $existing !== '') {
                    if ((string) $existing === (string) $providerId) {
                        $client->refresh();

                        return $client;
                    }

                    // Поздний запрос не перезаписывает чужую привязку.
                    throw ProviderLinkConflictException::forProvider($provider);
                }

                $this->assertProviderIdFreeScoped($provider, $providerId, $fresh->getKey(), $masterId, $workspaceId);

                $fresh->update([$field => $providerId]);
                $client->refresh();

                return $client;
            };

            if (DB::getDriverName() === 'pgsql') {
                return DB::transaction($run);
            }

            return $run();
        });
    }

    /**
     * @throws ProviderLinkConflictException
     */
    private function assertProviderIdFreeScoped(
        string $provider,
        string $providerId,
        string|int|null $exceptClientId,
        string $masterId,
        ?string $workspaceId,
    ): void {
        $field = $this->providerField($provider);

        if ($field === null || $providerId === '') {
            return;
        }

        $query = $this->queryInScopeById($masterId, $workspaceId)->where($field, $providerId);

        if ($exceptClientId !== null) {
            $query->where('id', '!=', $exceptClientId);
        }

        if ($query->exists()) {
            throw ProviderLinkConflictException::forProvider($provider);
        }
    }

    /**
     * Та же область, что и у поиска телефона, но без условия по phone —
     * для проверки занятости provider_id.
     */
    /**
     * @return Builder<Client>
     */
    private function queryInScopeById(string $masterId, ?string $workspaceId): Builder
    {
        return Client::query()->where(function (Builder $scope) use ($masterId, $workspaceId) {
            if ($workspaceId !== null && $workspaceId !== '') {
                $scope->where('workspace_id', $workspaceId)
                    ->orWhere(function (Builder $personal) use ($masterId) {
                        $personal->whereNull('workspace_id')
                            ->where('user_id', $masterId);
                    });
            } else {
                $scope->where('user_id', $masterId)
                    ->whereNull('workspace_id');
            }
        });
    }

    private function applyExistingClientUpdates(
        Client $client,
        string $telegramId,
        ?string $incomingName,
        string $masterId,
        ?string $workspaceId,
    ): Client {
        // Сначала привязка (может бросить конфликт — без побочных изменений),
        // затем безобидное улучшение placeholder-имени.
        if ($telegramId !== '') {
            $client = $this->linkProviderScoped($client, 'telegram', $telegramId, $masterId, $workspaceId);
        }

        $updates = [];

        if ($this->isUsableIncomingName($incomingName) && $this->isPlaceholder($client->name)) {
            $updates['name'] = $incomingName;
        }

        if ($updates !== []) {
            $client->update($updates);
        }

        return $client;
    }

    /**
     * PostgreSQL: сериализуем операции по (область, телефон), чтобы
     * конкурентные запросы не создали две карточки.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withPhoneLock(string $masterId, string $normalizedPhone, ?string $workspaceId, \Closure $callback): mixed
    {
        return $this->withPhoneLocks([$normalizedPhone], $masterId, $workspaceId, $callback);
    }

    /**
     * Блокировки одной/нескольких телефонных позиций области: ключи
     * захватываются в едином порядке (по возрастанию), чтобы конкурирующие
     * смены старого/нового номеров не перегрызались между собой.
     *
     * @template TReturn
     *
     * @param  list<string>  $phones
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withPhoneLocks(array $phones, string $masterId, ?string $workspaceId, \Closure $callback): mixed
    {
        $run = fn (): mixed => $callback();

        if (DB::getDriverName() !== 'pgsql') {
            return $run();
        }

        $keys = [];

        foreach ($phones as $phone) {
            $keys[$this->phoneLockKey($masterId, $phone, $workspaceId)] = true;
        }

        $keys = array_keys($keys);
        sort($keys, SORT_NUMERIC);

        return DB::transaction(function () use ($keys, $run) {
            foreach ($keys as $key) {
                DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$key]);
            }

            return $run();
        });
    }

    /**
     * Advisory-блокировка привязки: одна и та же (область, provider, id)
     * сериализует конкурирующие привязки; разные области не мешают друг другу.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withProviderLock(
        string $masterId,
        ?string $workspaceId,
        string $provider,
        string $providerId,
        \Closure $callback,
    ): mixed {
        $run = fn (): mixed => $callback();

        if (DB::getDriverName() !== 'pgsql') {
            return $run();
        }

        $scopeKey = $this->scopeKey($masterId, $workspaceId);
        $lockKey = sprintf('%u', crc32("client-provider:{$scopeKey}:{$provider}:{$providerId}"));

        return DB::transaction(function () use ($lockKey, $run) {
            DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$lockKey]);

            return $run();
        });
    }

    private function phoneLockKey(string $masterId, string $normalizedPhone, ?string $workspaceId): string
    {
        $scopeKey = $this->scopeKey($masterId, $workspaceId);

        return sprintf('%u', crc32("client-phone:{$scopeKey}:{$normalizedPhone}"));
    }

    private function scopeKey(string $masterId, ?string $workspaceId): string
    {
        return $workspaceId !== null && $workspaceId !== ''
            ? 'ws:'.$workspaceId
            : 'master:'.$masterId;
    }

    /**
     * Область привязки конкретной карточки: её workspace, а для legacy
     * персональной карточки — текущий workspace её мастера.
     */
    private function scopeWorkspaceId(Client $client): ?string
    {
        if ($client->workspace_id !== null && $client->workspace_id !== '') {
            return (string) $client->workspace_id;
        }

        /** @var User|null $master */
        $master = $client->master;
        $masterWorkspace = $master?->workspace_id;

        return $masterWorkspace !== null && $masterWorkspace !== ''
            ? (string) $masterWorkspace
            : null;
    }

    private function normalizeWorkspaceId(?string $workspaceId): ?string
    {
        return $workspaceId !== null && $workspaceId !== '' ? $workspaceId : null;
    }

    private function providerField(string $provider): ?string
    {
        return match ($provider) {
            'telegram' => 'telegram_id',
            'max' => 'max_id',
            'vk' => 'vk_id',
            default => null,
        };
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
