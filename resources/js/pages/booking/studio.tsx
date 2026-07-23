import { Head, Link, router, usePage } from '@inertiajs/react';
import { Users, ArrowLeft, ChevronRight, Clock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';
import { getInitials } from '@/lib/utils';

Studio.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════ Types ═══════════════ */

interface Workspace {
    id: string;
    name: string;
    slug: string;
}

interface Master {
    id: string;
    name: string;
    master_slug: string;
    avatar_url: string | null;
    specialty: string | null;
    price?: number;
    duration_minutes?: number;
    service_id?: string;
}

interface PageProps {
    workspace: Workspace;
    masters: Master[];
    service?: string;
}

/* ═══════════════ Master Card ═══════════════ */

function MasterCard({ master, studioSlug, serviceTitle }: { master: Master; studioSlug: string; serviceTitle?: string }) {
    const initials = getInitials(master.name);

    function handleClick() {
        const params = new URLSearchParams({ master: master.master_slug });
        if (serviceTitle) {
            params.set('service', serviceTitle);
        }
        router.visit(`/studio/${studioSlug}?${params.toString()}`);
    }

    return (
        <button
            onClick={handleClick}
            className="w-full rounded-2xl border border-stone-200/60 bg-white/70 p-4 text-left transition-all hover:border-stone-300 hover:bg-white hover:shadow-lg hover:shadow-stone-900/5 dark:border-stone-700/40 dark:bg-stone-900/50 dark:hover:border-stone-600 dark:hover:shadow-stone-100/5"
        >
            <div className="flex items-center gap-4">
                {master.avatar_url ? (
                    <img
                        src={master.avatar_url}
                        alt={master.name}
                        className="size-14 rounded-full object-cover"
                    />
                ) : (
                    <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-stone-900 text-base font-bold text-white dark:bg-stone-100 dark:text-stone-900">
                        {initials}
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-base font-semibold text-stone-900 dark:text-stone-50">
                        {master.name}
                    </p>
                    {master.specialty && (
                        <p className="mt-0.5 truncate text-sm text-stone-400 dark:text-stone-500">
                            {master.specialty}
                        </p>
                    )}
                    {master.price !== undefined && master.duration_minutes !== undefined && (
                        <div className="mt-1 flex items-center gap-3 text-xs text-stone-400 dark:text-stone-500">
                            <span className="font-semibold text-stone-700 dark:text-stone-300">
                                {master.price.toLocaleString('ru-RU')} ₽
                            </span>
                            <span className="flex items-center gap-1">
                                <Clock className="size-3" />
                                {master.duration_minutes} мин
                            </span>
                        </div>
                    )}
                </div>
                <ChevronRight className="size-5 shrink-0 text-stone-300 dark:text-stone-600" />
            </div>
        </button>
    );
}

/* ═══════════════ Empty State ═══════════════ */

function EmptyState({ serviceTitle }: { serviceTitle?: string }) {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center">
            <div className="flex size-16 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                <Users className="size-8 text-stone-400 dark:text-stone-500" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-stone-900 dark:text-stone-50">
                {serviceTitle ? 'Нет мастеров' : 'Нет мастеров'}
            </h2>
            <p className="mt-1 max-w-xs text-sm text-stone-400 dark:text-stone-500">
                {serviceTitle
                    ? 'Нет мастеров для этой услуги'
                    : 'В студии пока нет мастеров для записи'
                }
            </p>
        </div>
    );
}

/* ═══════════════ Main Component ═══════════════ */

export default function Studio() {
    const { workspace, masters, service } = usePage<PageProps>().props;

    return (
        <>
            <Head title={service ? `${service} — Мастера` : `${workspace.name} — Запись`} />

            <div className="mx-auto flex min-h-screen max-w-md flex-col bg-[#FAF8F5] dark:bg-[#121110]">
                {/* Header */}
                <div className="border-b border-stone-200/50 px-5 py-4 dark:border-stone-800/50">
                    <div className="flex items-center gap-3">
                        <Link
                            href={`/studio/${workspace.slug}`}
                            className="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-stone-200/60 dark:hover:bg-stone-700/60"
                        >
                            <ArrowLeft className="size-5 text-stone-600 dark:text-stone-400" />
                        </Link>
                        <div className="flex-1 text-center">
                            <h1 className="text-base font-semibold text-stone-900 dark:text-stone-50">
                                {service || workspace.name}
                            </h1>
                            <p className="text-xs text-stone-400 dark:text-stone-500">
                                {service ? 'Выберите мастера' : 'Выберите мастера'}
                            </p>
                        </div>
                        <div className="size-9" />
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {masters.length === 0 ? (
                        <EmptyState serviceTitle={service} />
                    ) : (
                        <div className="space-y-3">
                            {masters.map((master) => (
                                <MasterCard
                                    key={master.id}
                                    master={master}
                                    studioSlug={workspace.slug}
                                    serviceTitle={service}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
