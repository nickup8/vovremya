<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Диагностический поиск дублей клиентов по нормализованному телефону.
 * Только чтение: без изменений данных, отправок и удаления файлов.
 *
 * Группы соответствуют областям рабочего поиска (ClientMergeService):
 * область workspace = карточки workspace + legacy workspace_id=NULL
 * каждого мастера этого workspace; область legacy-мастера — только его
 * персональные карточки.
 */
class FindClientPhoneDuplicates extends Command
{
    protected $signature = 'clients:find-duplicates
        {--master= : Ограничить одной областью master (user_id)}
        {--workspace= : Ограничить одной областью workspace_id}
        {--limit=50 : Максимум групп в выводе}';

    protected $description = 'Показать группы клиентов с одним нормализованным телефоном (диагностика, без изменений данных)';

    public function handle(): int
    {
        $masterId = $this->option('master');
        $workspaceId = $this->option('workspace');
        $limit = max(1, (int) $this->option('limit'));

        $query = Client::query()->withCount('appointments');

        $mastersInWorkspace = null;

        if ($workspaceId) {
            // Legacy-карточки workspace_id=NULL включаем только для мастеров
            // этого workspace — чужие персональные карточки не подмешиваем.
            $mastersInWorkspace = User::where('workspace_id', $workspaceId)->pluck('id');

            $query->where(function ($q) use ($workspaceId, $mastersInWorkspace) {
                $q->where('workspace_id', $workspaceId)
                    ->orWhere(function ($own) use ($mastersInWorkspace) {
                        $own->whereNull('workspace_id')
                            ->whereIn('user_id', $mastersInWorkspace);
                    });
            });
        }

        if ($masterId) {
            $masterWorkspace = User::where('id', $masterId)->value('workspace_id');

            // Та же область, что видит рабочий поиск по этому мастеру.
            $query->where(function ($q) use ($masterId, $masterWorkspace) {
                if ($masterWorkspace !== null && $masterWorkspace !== '') {
                    $q->where('workspace_id', $masterWorkspace)
                        ->orWhere(function ($own) use ($masterId) {
                            $own->whereNull('workspace_id')
                                ->where('user_id', $masterId);
                        });
                } else {
                    $q->where('user_id', $masterId)
                        ->whereNull('workspace_id');
                }
            });
        }

        $rows = $query->orderBy('user_id')->orderBy('phone')->get([
            'id',
            'user_id',
            'workspace_id',
            'phone',
            'name',
            'is_blocked',
            'telegram_id',
            'max_id',
            'vk_id',
            'appointments_count',
        ]);

        $groups = $this->buildGroups($rows);

        $totalGroups = count($groups);

        if ($totalGroups === 0) {
            $this->info('Дубли по нормализованному телефону не найдены.');

            return self::SUCCESS;
        }

        $this->info("Найдено групп-дублей: {$totalGroups}");
        $this->newLine();

        $shown = 0;

        foreach ($groups as $group) {
            if ($shown >= $limit) {
                $this->warn('… и ещё '.($totalGroups - $shown).' групп (--limit).');
                break;
            }

            $this->line(sprintf(
                'Группа: %s phone_norm=%s',
                $group['label'],
                Phone::mask($group['phone']),
            ));

            foreach ($group['cards'] as $client) {
                $providers = [];
                if (! empty($client->telegram_id)) {
                    $providers[] = 'tg';
                }
                if (! empty($client->max_id)) {
                    $providers[] = 'max';
                }
                if (! empty($client->vk_id)) {
                    $providers[] = 'vk';
                }

                $this->line(sprintf(
                    '  - id=%s phone_raw=%s name=%s appts=%d blocked=%s providers=%s',
                    $client->id,
                    Phone::mask($client->phone),
                    $client->name,
                    (int) $client->appointments_count,
                    $client->is_blocked ? 'yes' : 'no',
                    $providers === [] ? '—' : implode(',', $providers),
                ));
            }

            foreach ($this->providerConflicts($group['cards']) as $conflict) {
                $this->warn('  CONFLICT: '.$conflict);
            }

            $this->newLine();
            $shown++;
        }

        return self::SUCCESS;
    }

    /**
     * Группы, в которых рабочий поиск увидел бы неоднозначность:
     * все карточки одной группы лежат в одной области (мастер/workspace)
     * и имеют один нормализованный телефон.
     *
     * @param  Collection<int, Client>  $rows
     * @return list<array{label: string, phone: string, cards: Collection<int, Client>}>
     */
    private function buildGroups(Collection $rows): array
    {
        $groups = [];

        $byPhone = $rows
            ->groupBy(function (Client $client) {
                $normalized = Phone::normalize($client->phone);

                return $normalized ?? 'invalid:'.$client->phone;
            })
            ->filter(function ($phoneRows, string $key) {
                return ! str_starts_with($key, 'invalid:');
            });

        $masterWorkspaces = User::whereIn('id', $rows->pluck('user_id')->unique())
            ->pluck('workspace_id', 'id');

        foreach ($byPhone as $normalized => $phoneRows) {
            /** @var Collection<int, Client> $phoneRows */
            $wsGroups = $phoneRows->whereNotNull('workspace_id')->groupBy('workspace_id');
            $nullGroups = $phoneRows->whereNull('workspace_id')->groupBy('user_id');

            foreach ($wsGroups as $wsId => $wsCards) {
                if ($wsCards->count() > 1) {
                    $groups[] = [
                        'label' => sprintf('master=ALL workspace=%s', $wsId),
                        'phone' => (string) $normalized,
                        'cards' => $wsCards->values(),
                    ];
                }

                foreach ($nullGroups as $masterId => $nullCards) {
                    if ((string) $masterWorkspaces->get($masterId) !== (string) $wsId) {
                        continue;
                    }

                    $combined = $wsCards->concat($nullCards)->values();

                    if ($combined->count() > 1) {
                        $groups[] = [
                            'label' => sprintf('master=%s workspace=%s', $masterId, $wsId),
                            'phone' => (string) $normalized,
                            'cards' => $combined,
                        ];
                    }
                }
            }

            foreach ($nullGroups as $masterId => $nullCards) {
                $masterWs = $masterWorkspaces->get($masterId);

                // Уже показано вместе с карточками workspace мастера.
                if ($masterWs !== null && $wsGroups->has($masterWs)) {
                    continue;
                }

                if ($nullCards->count() > 1) {
                    $groups[] = [
                        'label' => sprintf('master=%s workspace=NULL', $masterId),
                        'phone' => (string) $normalized,
                        'cards' => $nullCards->values(),
                    ];
                }
            }
        }

        return $groups;
    }

    /**
     * @param  Collection<int, Client>  $group
     * @return list<string>
     */
    private function providerConflicts(Collection $group): array
    {
        $conflicts = [];

        foreach (['telegram_id' => 'telegram', 'max_id' => 'max', 'vk_id' => 'vk'] as $field => $label) {
            $ids = $group
                ->map(fn (Client $c) => $c->{$field})
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->unique();

            if ($ids->count() > 1) {
                $conflicts[] = "разные {$label}-привязки в одной группе дублей ({$ids->count()})";
            }

            $linkedCards = $group->filter(fn (Client $c) => ! empty($c->{$field}))->count();
            if ($linkedCards > 1 && $ids->count() === 1) {
                $conflicts[] = "один {$label}-id на нескольких карточках группы";
            }
        }

        return $conflicts;
    }
}
