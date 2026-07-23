import { Head, router, usePage } from '@inertiajs/react';
import { Users, ArrowLeft, ChevronRight } from 'lucide-react';
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
}

interface PageProps {
    workspace: Workspace;
    masters: Master[];
}

/* ═══════════════ Master Card ═══════════════ */

function MasterCard({ master, studioSlug }: { master: Master; studioSlug: string }) {
    const initials = getInitials(master.name);

    function handleClick() {
        router.visit(`/studio/${studioSlug}?master=${master.master_slug}`);
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
                </div>
                <ChevronRight className="size-5 shrink-0 text-stone-300 dark:text-stone-600" />
            </div>
        </button>
    );
}

/* ═══════════════ Empty State ═══════════════ */

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center">
            <div className="flex size-16 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                <Users className="size-8 text-stone-400 dark:text-stone-500" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-stone-900 dark:text-stone-50">
                Нет мастеров
            </h2>
            <p className="mt-1 max-w-xs text-sm text-stone-400 dark:text-stone-500">
                В студии пока нет мастеров для записи
            </p>
        </div>
    );
}

/* ═══════════════ Main Component ═══════════════ */

export default function Studio() {
    const { workspace, masters } = usePage<PageProps>().props;

    return (
        <>
            <Head title={`${workspace.name} — Запись`} />

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
                                Выберите мастера
                            </p>
                        </div>
                        <div className="size-9" />
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {masters.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="space-y-3">
                            {masters.map((master) => (
                                <MasterCard
                                    key={master.id}
                                    master={master}
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
