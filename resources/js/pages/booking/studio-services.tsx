import { Head, router, usePage } from '@inertiajs/react';
import { Scissors, Clock, ChevronRight } from 'lucide-react';
import PublicLayout from '@/layouts/PublicLayout';

StudioServices.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════ Types ═══════════════ */

interface Workspace {
    id: string;
    name: string;
    slug: string;
}

interface StudioService {
    title: string;
    masters_count: number;
    price_from: number;
    duration_min: number;
    duration_max: number;
}

interface PageProps {
    workspace: Workspace;
    services: StudioService[];
}

/* ═══════════════ Service Card ═══════════════ */

function ServiceCard({ service, studioSlug }: { service: StudioService; studioSlug: string }) {
    function handleClick() {
        router.visit(`/studio/${studioSlug}?service=${encodeURIComponent(service.title)}`);
    }

    const durationText = service.duration_min === service.duration_max
        ? `${service.duration_min} мин`
        : `${service.duration_min}–${service.duration_max} мин`;

    return (
        <button
            onClick={handleClick}
            className="w-full rounded-2xl border border-stone-200/60 bg-white/70 p-4 text-left transition-all hover:border-stone-300 hover:bg-white hover:shadow-lg hover:shadow-stone-900/5 dark:border-stone-700/40 dark:bg-stone-900/50 dark:hover:border-stone-600 dark:hover:shadow-stone-100/5"
        >
            <div className="flex items-center justify-between">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-base font-semibold text-stone-900 dark:text-stone-50">
                        {service.title}
                    </p>
                    <div className="mt-1 flex items-center gap-3 text-xs text-stone-400 dark:text-stone-500">
                        <span className="flex items-center gap-1">
                            <Clock className="size-3" />
                            {durationText}
                        </span>
                        <span>
                            {service.masters_count} {service.masters_count === 1 ? 'мастер' : 'мастеров'}
                        </span>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-bold text-stone-900 dark:text-stone-50">
                        от {service.price_from.toLocaleString('ru-RU')} ₽
                    </span>
                    <ChevronRight className="size-5 shrink-0 text-stone-300 dark:text-stone-600" />
                </div>
            </div>
        </button>
    );
}

/* ═══════════════ Empty State ═══════════════ */

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center">
            <div className="flex size-16 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                <Scissors className="size-8 text-stone-400 dark:text-stone-500" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-stone-900 dark:text-stone-50">
                Нет услуг
            </h2>
            <p className="mt-1 max-w-xs text-sm text-stone-400 dark:text-stone-500">
                В студии пока нет услуг для записи
            </p>
        </div>
    );
}

/* ═══════════════ Main Component ═══════════════ */

export default function StudioServices() {
    const { workspace, services } = usePage<PageProps>().props;

    return (
        <>
            <Head title={`${workspace.name} — Услуги`} />

            <div className="mx-auto flex min-h-screen max-w-md flex-col bg-[#FAF8F5] dark:bg-[#121110]">
                {/* Header */}
                <div className="border-b border-stone-200/50 px-5 py-4 dark:border-stone-800/50">
                    <div className="flex items-center gap-3">
                        <div className="size-9" />
                        <div className="flex-1 text-center">
                            <h1 className="text-base font-semibold text-stone-900 dark:text-stone-50">
                                {workspace.name}
                            </h1>
                            <p className="text-xs text-stone-400 dark:text-stone-500">
                                Выберите услугу
                            </p>
                        </div>
                        <div className="size-9" />
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {services.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="space-y-3">
                            {services.map((service) => (
                                <ServiceCard
                                    key={service.title}
                                    service={service}
                                    studioSlug={workspace.slug}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
