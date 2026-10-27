<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Диагностический поиск дублей клиентов по нормализованному телефону.
 * Только чтение: без изменений данных, отправок и удаления файлов.
 */
class FindClientPhoneDuplicates extends Command
{
    protected $signature = 'clients:find-duplicates
        {--master= : Ограничить одним master (user_id)}
        {--workspace= : Ограничить одним workspace_id}
        {--limit=50 : Максимум групп в выводе}';

    protected $description = 'Показать группы клиентов с одним нормализованным телефоном (диагностика, без изменений данных)';

    public function handle(): int
    {
        $masterId = $this->option('master');
        $workspaceId = $this->option('workspace');
        $limit = max(1, (int) $this->option('limit'));

        $query = Client::query()
            ->withCount('appointments')
            ->orderBy('user_id')
            ->orderBy('phone');

        if ($masterId) {
            $query->where('user_id', $masterId);
        }

        if ($workspaceId) {
            $query->where(function ($q) use ($workspaceId) {
                $q->where('workspace_id', $workspaceId)
                    ->orWhereNull('workspace_id');
            });
        }

        $rows = $query->get([
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

        $groups = $rows
            ->groupBy(function (Client $client) {
                $normalized = (string) Phone::normalize($client->phone);

                // В пределах workspace или персонально в пределах мастера.
                $scope = $client->workspace_id !== null
                    ? 'ws:'.$client->workspace_id
                    : 'master:'.$client->user_id;

                return $scope.'|'.$normalized;
            })
            ->filter(fn (Collection $group) => $group->count() > 1 && Phone::normalize($group->first()->phone) !== null)
            ->sortKeys();

        $totalGroups = $groups->count();

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

            $first = $group->first();
            $normalized = (string) Phone::normalize($first->phone);

            $this->line(sprintf(
                'Группа: master=%s workspace=%s phone_norm=%s',
                $first->user_id,
                $first->workspace_id ?? 'NULL',
                Phone::mask($normalized),
            ));

            foreach ($group as $client) {
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

            $conflicts = $this->providerConflicts($group);
            foreach ($conflicts as $conflict) {
                $this->warn('  CONFLICT: '.$conflict);
            }

            $this->newLine();
            $shown++;
        }

        return self::SUCCESS;
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
