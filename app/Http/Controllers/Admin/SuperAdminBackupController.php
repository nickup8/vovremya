<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Http\Controllers\Controller;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Backups\DatabaseBackupService;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Резервные копии БД — только ROOT (middleware super_admin).
 * Ограниченные platform admin сюда не получают доступ ни к странице,
 * ни к endpoints.
 */
class SuperAdminBackupController extends Controller
{
    public function index(): Response
    {
        $backups = DatabaseBackup::query()
            ->with('requester:id,name')
            ->orderByDesc('requested_at')
            ->limit(30)
            ->get()
            ->map(fn (DatabaseBackup $backup) => $this->toArray($backup));

        $lastSuccess = DatabaseBackup::query()
            ->where('status', BackupStatus::Success->value)
            ->orderByDesc('finished_at')
            ->first(['finished_at']);

        $warningAfterHours = (int) config('backups.warning_after_hours', 48);
        $lastSuccessAt = $lastSuccess?->finished_at;

        return Inertia::render('SuperAdmin/Backups', [
            'backups' => $backups,
            'last_success_at' => $lastSuccessAt?->toIso8601String(),
            'stale_warning' => $lastSuccessAt === null
                || $lastSuccessAt->lt(now()->subHours($warningAfterHours)),
            'warning_after_hours' => $warningAfterHours,
            'active' => DatabaseBackup::query()
                ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Running->value])
                ->exists(),
            'schedule' => [
                'enabled' => (bool) config('backups.scheduled.enabled'),
                'time' => (string) config('backups.scheduled.time', '03:30'),
                'timezone' => (string) config('backups.scheduled.timezone', 'Europe/Moscow'),
            ],
            'display_timezone' => 'Europe/Moscow',
        ]);
    }

    public function store(Request $request, DatabaseBackupService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $backup = $service->request(BackupTrigger::Manual, $user);
        } catch (BackupAlreadyRunning $e) {
            return back()->with('error', $e->getMessage());
        }

        app(SuperAdminAuditLogger::class)->log(
            $user,
            'backup.requested',
            $backup,
            metadata: [
                'backup_id' => $backup->getKey(),
                'trigger' => 'manual',
            ],
        );

        return back()->with('success', 'Задание резервной копии поставлено в очередь.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(DatabaseBackup $backup): array
    {
        return [
            'id' => $backup->getKey(),
            'trigger' => $backup->trigger_type->value,
            'status' => $backup->status->value,
            'requested_at' => $backup->requested_at?->toIso8601String(),
            'started_at' => $backup->started_at?->toIso8601String(),
            'finished_at' => $backup->finished_at?->toIso8601String(),
            'size_bytes' => $backup->size_bytes,
            'sha256' => $backup->sha256,
            'error_message' => $backup->error_message,
            'prune_error_message' => $backup->prune_error_message,
            'requested_by' => $backup->requester
                ? ['id' => $backup->requester->getKey(), 'name' => $backup->requester->name]
                : null,
        ];
    }
}
