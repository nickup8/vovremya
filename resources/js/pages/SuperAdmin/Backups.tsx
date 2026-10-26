import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';

interface BackupEntry {
    id: string;
    trigger: 'manual' | 'scheduled';
    status: 'queued' | 'running' | 'success' | 'failed';
    requested_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    size_bytes: number | null;
    sha256: string | null;
    error_message: string | null;
    prune_error_message: string | null;
    requested_by: { id: string; name: string } | null;
}

interface BackupsProps {
    backups: BackupEntry[];
    last_success_at: string | null;
    stale_warning: boolean;
    warning_after_hours: number;
    active: boolean;
    schedule: {
        enabled: boolean;
        time: string;
        timezone: string;
    };
    display_timezone: string;
    flash?: {
        success?: string;
        error?: string;
    };
}

const STATUS_LABELS: Record<BackupEntry['status'], string> = {
    queued: 'Ожидает',
    running: 'Выполняется',
    success: 'Успешно',
    failed: 'Ошибка',
};

const STATUS_STYLES: Record<BackupEntry['status'], string> = {
    queued: 'bg-[#F0EEEA] text-[#62615F]',
    running: 'bg-[#FFF3E4] text-[#B35A00]',
    success: 'bg-[#E8F6EF] text-[#16875E]',
    failed: 'bg-[#FCEEEF] text-[#C44351]',
};

const TRIGGER_LABELS: Record<BackupEntry['trigger'], string> = {
    manual: 'Вручную',
    scheduled: 'Автоматически',
};

const ACTIVE_POLL_MS = 5000;

function formatMsk(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return (
        new Date(iso).toLocaleString('ru-RU', {
            timeZone: 'Europe/Moscow',
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }) + ' МСК'
    );
}

function formatSize(bytes: number | null): string {
    if (bytes === null || bytes <= 0) {
        return '—';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} КБ`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
}

export default function Backups() {
    const {
        backups,
        last_success_at,
        stale_warning,
        warning_after_hours,
        active,
        schedule,
        display_timezone,
        flash,
    } = usePage().props as BackupsProps;

    const [submitting, setSubmitting] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const busy = submitting || active;

    function reloadStatus() {
        if (refreshing) {
            return;
        }

        setRefreshing(true);
        router.reload({
            only: ['backups', 'last_success_at', 'stale_warning', 'active'],
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    }

    useEffect(() => {
        if (pollRef.current !== null) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }

        if (!active) {
            return undefined;
        }

        pollRef.current = setInterval(() => {
            router.reload({
                only: ['backups', 'last_success_at', 'stale_warning', 'active'],
                preserveScroll: true,
            });
        }, ACTIVE_POLL_MS);

        return () => {
            if (pollRef.current !== null) {
                clearInterval(pollRef.current);
                pollRef.current = null;
            }
        };
    }, [active]);

    function createBackup() {
        if (busy) {
            return;
        }

        setSubmitting(true);
        router.post(
            '/admin-root/backups',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    }

    const timezoneLabel =
        display_timezone === 'Europe/Moscow' ? 'МСК' : display_timezone;

    return (
        <>
            <Head title="Резервные копии" />
            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-4xl px-4 py-10">
                    <h1 className="text-2xl font-bold tracking-tight text-[#181818]">
                        Резервные копии
                    </h1>
                    <p className="mt-1.5 text-sm text-[#62615F]">
                        Копии базы данных PostgreSQL. Все даты — в часовом поясе{' '}
                        {timezoneLabel} (Europe/Moscow).
                    </p>

                    <SuperAdminNav current="backups" />

                    {flash?.success && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#16875E]">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#C44351]">
                            {flash.error}
                        </div>
                    )}

                    {stale_warning && (
                        <div
                            className="mt-5 rounded-xl border border-[#F0D9A8] bg-[#FFF8EC] px-4 py-3 text-sm font-medium text-[#B35A00]"
                            data-testid="stale-warning"
                        >
                            Предупреждение: успешной резервной копии нет дольше{' '}
                            {warning_after_hours} ч. Последняя успешная:{' '}
                            {formatMsk(last_success_at)}.
                        </div>
                    )}

                    <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                        <div className="text-sm text-[#62615F]">
                            Последняя успешная копия:{' '}
                            <span
                                className="font-medium text-[#181818]"
                                data-testid="last-success"
                            >
                                {formatMsk(last_success_at)}
                            </span>
                            <span className="mt-1 block text-xs text-[#8E8A85]">
                                Автозапуск:{' '}
                                {schedule.enabled
                                    ? `ежедневно в ${schedule.time} (${schedule.timezone})`
                                    : 'выключен (BACKUP_SCHEDULED_ENABLED=false)'}
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={reloadStatus}
                                disabled={refreshing}
                                data-testid="refresh-status"
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] transition-colors hover:bg-[#F0EEEA] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {refreshing ? 'Обновляем…' : 'Обновить статус'}
                            </button>
                            <button
                                type="button"
                                onClick={createBackup}
                                disabled={busy}
                                data-testid="create-backup"
                                className="rounded-xl bg-[#FF5A1F] px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {active
                                    ? 'Копия выполняется…'
                                    : 'Создать копию'}
                            </button>
                        </div>
                    </div>

                    {backups.length === 0 ? (
                        <div className="mt-10 text-center">
                            <p className="text-sm font-semibold text-[#181818]">
                                Копий пока нет
                            </p>
                            <p className="mt-1 text-sm text-[#8E8A85]">
                                Нажмите «Создать копию» — задание уйдёт в
                                очередь.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-6 overflow-x-auto rounded-2xl border border-[#E7E4DF] bg-white">
                            <table
                                className="w-full text-sm"
                                data-testid="backups-table"
                            >
                                <thead>
                                    <tr className="border-b border-[#F0EEEA] text-left">
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Запрошена
                                        </th>
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Источник
                                        </th>
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Состояние
                                        </th>
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Размер
                                        </th>
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Завершена
                                        </th>
                                        <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">
                                            Детали
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#F0EEEA]">
                                    {backups.map((backup) => (
                                        <tr
                                            key={backup.id}
                                            className="align-top"
                                            data-testid="backup-row"
                                            data-status={backup.status}
                                        >
                                            <td className="px-4 py-3 whitespace-nowrap text-[#62615F]">
                                                {formatMsk(backup.requested_at)}
                                                {backup.requested_by && (
                                                    <span className="mt-0.5 block text-xs text-[#8E8A85]">
                                                        {
                                                            backup.requested_by
                                                                .name
                                                        }
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-[#181818]">
                                                {TRIGGER_LABELS[backup.trigger]}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-block rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_STYLES[backup.status]}`}
                                                >
                                                    {
                                                        STATUS_LABELS[
                                                            backup.status
                                                        ]
                                                    }
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-[#181818]">
                                                {formatSize(backup.size_bytes)}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-[#62615F]">
                                                {formatMsk(backup.finished_at)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {backup.status === 'failed' &&
                                                    backup.error_message && (
                                                        <p className="text-xs text-[#C44351]">
                                                            {
                                                                backup.error_message
                                                            }
                                                        </p>
                                                    )}
                                                {backup.prune_error_message && (
                                                    <p className="mt-1 text-xs text-[#B35A00]">
                                                        {
                                                            backup.prune_error_message
                                                        }
                                                    </p>
                                                )}
                                                {backup.status === 'success' &&
                                                    backup.sha256 && (
                                                        <p
                                                            className="text-xs text-[#8E8A85]"
                                                            title={
                                                                backup.sha256
                                                            }
                                                        >
                                                            SHA-256:{' '}
                                                            {backup.sha256.slice(
                                                                0,
                                                                12,
                                                            )}
                                                            …
                                                        </p>
                                                    )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
