import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import WorkingHoursCard from '@/components/admin/schedule/WorkingHoursCard';
import BlockedTimesCard from '@/components/admin/schedule/BlockedTimesCard';
import type { WorkingHour } from '@/components/admin/schedule/WorkingHoursCard';

interface ScheduleProfile {
    id: string;
    timezone: string;
}

interface PageProps {
    workingHours: WorkingHour[];
    blockedTimes: { id: string; start_datetime: string; end_datetime: string; reason: string }[];
    profile: ScheduleProfile;
    auth?: { user?: { name?: string; [key: string]: unknown } };
    [key: string]: unknown;
}

export default function SchedulePage() {
    const { profile, workingHours, auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Расписание — Вовремя" />

            <AdminLayout title="Расписание" auth={auth}>
                <div className="max-w-[1280px] space-y-0">

                    {/* ═══ Недоступное время ═══ */}
                    <div className="py-6">
                        <BlockedTimesCard masterId={profile.id} timezone={profile.timezone} />
                    </div>

                    {/* ═══ Рабочие часы ═══ */}
                    <div className="border-t border-[var(--color-line)] py-6">
                        <div className="mb-4 text-[15px] font-semibold text-[var(--color-ink)]">
                            Рабочие часы
                        </div>
                        <WorkingHoursCard
                            workingHours={workingHours}
                            masterId={profile.id}
                        />
                    </div>

                </div>
            </AdminLayout>
        </>
    );
}
