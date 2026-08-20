<?php

namespace App\Services\Analytics;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\TrackingLink;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Финансовая аналитика каналов записи (source analytics).
 *
 * Единый calculation layer: полный список источников используется вкладкой,
 * TOP-5 получается сортировкой/лимитом из того же результата (без дублирования SQL).
 *
 * Периоды разнесены по собственным датам событий:
 *   — «Записи»       → created_at
 *   — «Отменённые»   → cancelled_at
 *   — «Завершённые»/выручка → completed_at (+ статус Paid)
 *
 * Source classification (стабильные ключи):
 *   1. tracking_link_id  → пользовательская ссылка (key "link:{id}")
 *   2. source = Admin    → системный "Записано мастером" (key "manual")
 *   3. иначе             → системный "Без источника / Direct" (key "direct")
 *
 * Ссылки группируются по id (не по имени) — одинаковые имена не склеиваются.
 */
class SourceAnalyticsService
{
    public const KEY_MANUAL = 'manual';

    public const KEY_DIRECT = 'direct';

    public const TYPE_TRACKING = 'tracking';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_DIRECT = 'direct';

    public const NAME_MANUAL = 'Записано мастером';

    public const NAME_DIRECT = 'Без источника / Direct';

    /**
     * Возвращает список источников с метриками за период.
     * $periodStart / $periodEnd — границы в UTC (уже пересчитанные из tz мастера).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildForMaster(User $master, Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->loadRelevantAppointments($master, $periodStart, $periodEnd);
        $firstCompletedByClient = $this->firstCompletedDateMap($master);
        $linkNames = $this->trackingLinkNames($master);

        /** @var array<string, array<string, mixed>> $sources */
        $sources = [];

        // Клиенты NEW/RETURNING на источник: distinct client_id, разнесённые по семантике визита.
        $newClientSets = [];       // key => [client_id => true]
        $returningClientSets = []; // key => [client_id => true]

        $ensure = function (string $key, string $type, string $name) use (&$sources): void {
            if (! isset($sources[$key])) {
                $sources[$key] = [
                    'key' => $key,
                    'type' => $type,
                    'name' => $name,
                    'created_count' => 0,
                    'cancelled_count' => 0,
                    'completed_count' => 0,
                    'revenue' => 0.0,
                    'new_clients_count' => 0,
                    'returning_clients_count' => 0,
                    'average_check' => 0.0,
                ];
            }
        };

        foreach ($rows as $row) {
            [$key, $type, $name] = $this->classify($row, $linkNames);
            $ensure($key, $type, $name);

            // «Записи» — по created_at.
            if ($this->inPeriod($row->created_at, $periodStart, $periodEnd)) {
                $sources[$key]['created_count']++;
            }

            // «Отменённые» — по cancelled_at.
            if ($row->cancelled_at !== null && $this->inPeriod($row->cancelled_at, $periodStart, $periodEnd)) {
                $sources[$key]['cancelled_count']++;
            }

            // «Завершённые» + выручка — по completed_at при статусе Paid.
            $isCompletedInPeriod = $row->status === AppointmentStatus::Paid
                && $row->completed_at !== null
                && $this->inPeriod($row->completed_at, $periodStart, $periodEnd);

            if ($isCompletedInPeriod) {
                $sources[$key]['completed_count']++;
                $sources[$key]['revenue'] += (float) ($row->price ?? 0);

                if ($row->client_id !== null) {
                    $firstDate = $firstCompletedByClient[$row->client_id] ?? null;
                    $isNew = $firstDate !== null
                        && $row->completed_at->getTimestamp() <= $firstDate->getTimestamp();

                    if ($isNew) {
                        $newClientSets[$key][$row->client_id] = true;
                    } else {
                        $returningClientSets[$key][$row->client_id] = true;
                    }
                }
            }
        }

        foreach ($sources as $key => &$src) {
            $src['new_clients_count'] = count($newClientSets[$key] ?? []);
            $src['returning_clients_count'] = count($returningClientSets[$key] ?? []);
            $src['revenue'] = round($src['revenue'], 2);
            $src['average_check'] = $src['completed_count'] > 0
                ? round($src['revenue'] / $src['completed_count'], 2)
                : 0.0;
        }
        unset($src);

        // Оставляем только источники с активностью в периоде.
        $result = array_values(array_filter($sources, fn ($s) => $s['created_count'] > 0
            || $s['cancelled_count'] > 0
            || $s['completed_count'] > 0));

        // Стабильная сортировка: по выручке убыв., затем по завершённым.
        usort($result, function ($a, $b) {
            return [$b['revenue'], $b['completed_count']] <=> [$a['revenue'], $a['completed_count']];
        });

        return $result;
    }

    /**
     * TOP-N из того же расчёта (по выручке убыв.).
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    public function topByRevenue(array $sources, int $limit = 5): array
    {
        return array_slice($sources, 0, $limit);
    }

    /**
     * @param  array<string, string>  $linkNames
     * @return array{0:string,1:string,2:string} [key, type, name]
     */
    private function classify(Appointment $row, array $linkNames): array
    {
        if ($row->tracking_link_id !== null) {
            $name = $linkNames[$row->tracking_link_id] ?? 'Ссылка';

            return ['link:'.$row->tracking_link_id, self::TYPE_TRACKING, $name];
        }

        if ($row->source === AppointmentSource::Admin) {
            return [self::KEY_MANUAL, self::TYPE_MANUAL, self::NAME_MANUAL];
        }

        return [self::KEY_DIRECT, self::TYPE_DIRECT, self::NAME_DIRECT];
    }

    private function inPeriod(?CarbonInterface $date, CarbonInterface $start, CarbonInterface $end): bool
    {
        return $date !== null && $date->betweenIncluded($start, $end);
    }

    /**
     * Все записи, попадающие хотя бы в один event-scope периода.
     *
     * @return Collection<int, Appointment>
     */
    private function loadRelevantAppointments(User $master, Carbon $start, Carbon $end): Collection
    {
        return Appointment::query()
            ->where('master_id', $master->id)
            ->select(['id', 'client_id', 'tracking_link_id', 'source', 'status', 'price', 'created_at', 'cancelled_at', 'completed_at'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                    ->orWhereBetween('cancelled_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('status', AppointmentStatus::Paid)
                            ->whereBetween('completed_at', [$start, $end]);
                    });
            })
            ->get();
    }

    /**
     * Карта client_id → дата первого завершённого визита у мастера (за всё время).
     * Один запрос — без N+1.
     *
     * @return array<string, Carbon>
     */
    private function firstCompletedDateMap(User $master): array
    {
        return Appointment::query()
            ->where('master_id', $master->id)
            ->where('status', AppointmentStatus::Paid)
            ->whereNotNull('completed_at')
            ->whereNotNull('client_id')
            ->selectRaw('client_id, MIN(completed_at) as first_completed_at')
            ->groupBy('client_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->client_id => Carbon::parse($r->first_completed_at)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function trackingLinkNames(User $master): array
    {
        return TrackingLink::query()
            ->where('master_id', $master->id)
            ->pluck('name', 'id')
            ->all();
    }
}
