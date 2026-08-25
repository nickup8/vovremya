<?php

namespace App\Services\Client;

use App\Enums\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReactivationCandidateService
{
    private const FUTURE_STATUSES = [
        AppointmentStatus::Booked->value,
        AppointmentStatus::PendingPayment->value,
        AppointmentStatus::Prepaid->value,
    ];

    /**
     * Find reactivation candidates for the authenticated master.
     *
     * Single set-based PostgreSQL query:
     *  - CTE latest_paid: DISTINCT ON (client_id, catalog_id) for latest paid visit
     *  - EXISTS: current master has active MasterService for catalog
     *  - NOT EXISTS: no future same-catalog active appointment
     *  - Due filter: completed_at + reactivation_days <= now
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

        // Client scope: matches Client::scopeForWorkspaceOrMaster()
        $clientScopeCondition = $user->workspace_id !== null
            ? 'c.workspace_id = :ws_id'
            : '(c.workspace_id IS NULL AND c.user_id = :master_id)';

        $bindings = [
            'master_id' => $user->id,
            'ws_id' => $user->workspace_id,
            'now' => $now->toDateTimeString(),
            'paid' => AppointmentStatus::Paid->value,
        ];

        $futureStatuses = self::FUTURE_STATUSES;

        // Build placeholders for NOT EXISTS IN clause
        $futureStatusPlaceholders = [];
        foreach ($futureStatuses as $i => $status) {
            $key = "future_status_{$i}";
            $futureStatusPlaceholders[] = ":{$key}";
            $bindings[$key] = $status;
        }
        $futureStatusList = implode(', ', $futureStatusPlaceholders);

        $sql = <<<SQL
            WITH latest_paid AS (
                SELECT DISTINCT ON (a.client_id, ms.catalog_id)
                    a.client_id,
                    ms.catalog_id,
                    a.id AS source_appointment_id,
                    a.completed_at AS last_visit_at,
                    c.name AS client_name,
                    sc.title AS service_name,
                    sc.reactivation_days
                FROM appointments a
                JOIN master_service ms ON ms.id = a.master_service_id
                JOIN service_catalog sc ON sc.id = ms.catalog_id
                JOIN clients c ON c.id = a.client_id
                WHERE a.status = :paid
                  AND a.completed_at IS NOT NULL
                  AND a.master_service_id IS NOT NULL
                  AND sc.is_active = true
                  AND sc.reactivation_days IS NOT NULL
                  AND c.is_blocked = false
                  AND c.disable_reactivation = false
                  AND {$clientScopeCondition}
                  AND EXISTS (
                      SELECT 1
                      FROM master_service current_ms
                      WHERE current_ms.master_id = :master_id
                        AND current_ms.catalog_id = sc.id
                        AND current_ms.is_active = true
                  )
                ORDER BY
                    a.client_id,
                    ms.catalog_id,
                    a.completed_at DESC,
                    a.id DESC
            )
            SELECT
                lp.client_id,
                lp.client_name,
                lp.catalog_id AS service_catalog_id,
                lp.service_name,
                lp.source_appointment_id,
                lp.last_visit_at,
                lp.reactivation_days,
                (lp.last_visit_at::timestamp + make_interval(days => lp.reactivation_days)) AS eligible_at
            FROM latest_paid lp
            WHERE (lp.last_visit_at::timestamp + make_interval(days => lp.reactivation_days)) <= :now
              AND NOT EXISTS (
                  SELECT 1
                  FROM appointments fa
                  JOIN master_service fms ON fms.id = fa.master_service_id
                  WHERE fa.client_id = lp.client_id
                    AND fms.catalog_id = lp.catalog_id
                    AND fa.status IN ({$futureStatusList})
                    AND fa.start_time > :now
              )
            ORDER BY
                (lp.last_visit_at::timestamp + make_interval(days => lp.reactivation_days)) ASC,
                lp.client_id ASC,
                lp.catalog_id ASC
            SQL;

        $rows = DB::select($sql, $bindings);

        $candidates = [];
        foreach ($rows as $row) {
            $eligibleAt = Carbon::parse($row->eligible_at);
            $secondsOverdue = $now->getTimestamp() - $eligibleAt->getTimestamp();
            $daysOverdue = max(0, (int) floor($secondsOverdue / 86400));

            $candidates[] = [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'service_catalog_id' => $row->service_catalog_id,
                'service_name' => $row->service_name,
                'source_appointment_id' => $row->source_appointment_id,
                'last_visit_at' => Carbon::parse($row->last_visit_at)->toIso8601String(),
                'reactivation_days' => (int) $row->reactivation_days,
                'eligible_at' => $eligibleAt->toIso8601String(),
                'days_overdue' => $daysOverdue,
            ];
        }

        return $candidates;
    }
}
