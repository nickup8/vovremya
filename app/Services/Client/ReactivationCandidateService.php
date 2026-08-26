<?php

namespace App\Services\Client;

use App\Enums\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReactivationCandidateService
{
    /**
     * Find reactivation candidates for the authenticated master.
     *
     * Each candidate = (client, service_catalog) where:
     *  1. client belongs to workspace/master scope
     *  2. client.is_blocked = false
     *  3. client.disable_reactivation = false
     *  4. service_catalog.is_active = true
     *  5. service_catalog.reactivation_days IS NOT NULL
     *  6. latest paid appointment with completed_at exists for this client+catalog
     *  7. completed_at + reactivation_days <= now
     *  8. no future active appointment for same catalog
     *  9. current master has active MasterService for this catalog
     *
     * @return array<int, array{
     *     client_id: string,
     *     client_name: string,
     *     service_catalog_id: string,
     *     service_name: string,
     *     source_appointment_id: string,
     *     last_visit_at: string,
     *     reactivation_days: int,
     *     eligible_at: string,
     *     days_overdue: int,
     * }>
     */
    public function findFor(User $user): array
    {
        $now = Carbon::now();

        // Step 1: Get current master's active catalog IDs
        $activeCatalogIds = DB::table('master_service')
            ->where('master_id', $user->id)
            ->where('is_active', true)
            ->pluck('catalog_id');

        if ($activeCatalogIds->isEmpty()) {
            return [];
        }

        // Step 2: Get eligible catalog services (active + has reactivation cycle)
        $eligibleCatalogs = DB::table('service_catalog')
            ->whereIn('id', $activeCatalogIds)
            ->where('is_active', true)
            ->whereNotNull('reactivation_days')
            ->get()
            ->keyBy('id');

        if ($eligibleCatalogs->isEmpty()) {
            return [];
        }

        // Step 3: Get clients in scope
        $clientQuery = DB::table('clients')
            ->where('is_blocked', false)
            ->where('disable_reactivation', false);

        if ($user->workspace_id !== null) {
            $clientQuery->where('workspace_id', $user->workspace_id);
        } else {
            $clientQuery->where('user_id', $user->id)->whereNull('workspace_id');
        }

        $clientIds = $clientQuery->pluck('id');

        if ($clientIds->isEmpty()) {
            return [];
        }

        // Step 4: Find latest paid+completed appointment per (client_id, catalog_id)
        // using DISTINCT ON (client_id, catalog_id)
        $latestVisits = DB::table('appointments as a')
            ->join('master_service as ms', 'ms.id', '=', 'a.master_service_id')
            ->select(
                'a.client_id',
                'ms.catalog_id',
                'a.id as appointment_id',
                'a.completed_at',
            )
            ->whereIn('a.client_id', $clientIds)
            ->whereIn('ms.catalog_id', $eligibleCatalogs->keys())
            ->where('a.status', AppointmentStatus::Paid->value)
            ->whereNotNull('a.completed_at')
            ->whereNotNull('a.master_service_id')
            ->orderByRaw('a.client_id, ms.catalog_id, a.completed_at DESC, a.id DESC')
            ->get();

        // Apply DISTINCT ON (client_id, catalog_id) in PHP
        $latestByKey = [];
        foreach ($latestVisits as $visit) {
            $key = $visit->client_id . '|' . $visit->catalog_id;
            if (! isset($latestByKey[$key])) {
                $latestByKey[$key] = $visit;
            }
        }

        if (empty($latestByKey)) {
            return [];
        }

        // Step 5: Filter by eligibility (completed_at + reactivation_days <= now)
        $dueVisits = [];
        foreach ($latestByKey as $key => $visit) {
            $catalog = $eligibleCatalogs->get($visit->catalog_id);
            if (! $catalog) {
                continue;
            }

            $completedAt = Carbon::parse($visit->completed_at);
            $eligibleAt = $completedAt->copy()->addDays($catalog->reactivation_days);

            if ($eligibleAt->lte($now)) {
                $dueVisits[$key] = (object) [
                    'client_id' => $visit->client_id,
                    'catalog_id' => $visit->catalog_id,
                    'appointment_id' => $visit->appointment_id,
                    'completed_at' => $completedAt,
                    'eligible_at' => $eligibleAt,
                    'reactivation_days' => $catalog->reactivation_days,
                    'service_name' => $catalog->title,
                ];
            }
        }

        if (empty($dueVisits)) {
            return [];
        }

        // Step 6: Suppress candidates with future active appointment for same catalog
        $activeFutureStatuses = [
            AppointmentStatus::Booked->value,
            AppointmentStatus::PendingPayment->value,
            AppointmentStatus::Prepaid->value,
        ];

        $dueClientIds = collect($dueVisits)->pluck('client_id')->unique()->values()->all();
        $dueCatalogIds = collect($dueVisits)->pluck('catalog_id')->unique()->values()->all();

        $futureAppointments = DB::table('appointments as a')
            ->join('master_service as ms', 'ms.id', '=', 'a.master_service_id')
            ->select('a.client_id', 'ms.catalog_id')
            ->whereIn('a.client_id', $dueClientIds)
            ->whereIn('ms.catalog_id', $dueCatalogIds)
            ->whereIn('a.status', $activeFutureStatuses)
            ->where('a.start_time', '>', $now)
            ->get();

        $suppressed = [];
        foreach ($futureAppointments as $fa) {
            $suppressed[$fa->client_id . '|' . $fa->catalog_id] = true;
        }

        // Step 7: Build result
        $clientNames = DB::table('clients')
            ->whereIn('id', $dueClientIds)
            ->pluck('name', 'id');

        $candidates = [];
        foreach ($dueVisits as $key => $visit) {
            if (isset($suppressed[$key])) {
                continue;
            }

            $secondsOverdue = $now->getTimestamp() - $visit->eligible_at->getTimestamp();
            $daysOverdue = max(0, (int) floor($secondsOverdue / 86400));

            $candidates[] = [
                'client_id' => $visit->client_id,
                'client_name' => $clientNames[$visit->client_id] ?? '',
                'service_catalog_id' => $visit->catalog_id,
                'service_name' => $visit->service_name,
                'source_appointment_id' => $visit->appointment_id,
                'last_visit_at' => $visit->completed_at->toIso8601String(),
                'reactivation_days' => $visit->reactivation_days,
                'eligible_at' => $visit->eligible_at->toIso8601String(),
                'days_overdue' => $daysOverdue,
            ];
        }

        // Sort: most overdue first, then by client_id, then catalog_id
        usort($candidates, function (array $a, array $b) {
            if ($b['days_overdue'] !== $a['days_overdue']) {
                return $b['days_overdue'] - $a['days_overdue'];
            }

            if ($a['client_id'] !== $b['client_id']) {
                return $a['client_id'] <=> $b['client_id'];
            }

            return $a['service_catalog_id'] <=> $b['service_catalog_id'];
        });

        return $candidates;
    }
}
