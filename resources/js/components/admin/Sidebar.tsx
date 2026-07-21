import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays, Users, BarChart3, Settings, RefreshCw, X, LogOut,
} from 'lucide-react';

const MENU_ITEMS = [
    { icon: CalendarDays, label: 'Календарь', href: '/admin/calendar' },
    { icon: Users, label: 'База клиентов', href: '/admin/clients' },
    { icon: BarChart3, label: 'Аналитика', href: '/admin/analytics' },
    { icon: Settings, label: 'Настройки профиля', href: '/admin/settings' },
];

interface SidebarProps {
    mobileOpen: boolean;
    onMobileClose: () => void;
}

export default function Sidebar({ mobileOpen, onMobileClose }: SidebarProps) {
    const { url, props } = usePage();
    const tariffName = (props as { auth?: { user?: { tariff_name?: string } } })?.auth?.user?.tariff_name || 'Free';

    function handleLogout() {
        router.post('/logout', {}, {
            onFinish: () => {
                window.location.href = '/';
            },
        });
    }

    const sidebarContent = (
        <div className="flex h-full flex-col justify-between bg-slate-950 text-white dark:bg-zinc-950">
            <div>
                <div className="flex h-16 items-center justify-between border-b border-slate-800 px-4 dark:border-zinc-800">
                    <div className="flex items-center gap-2">
                        <span className="text-lg font-bold tracking-tight">Вовремя</span>
                        <span className="rounded-full bg-emerald-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-400">
                            {tariffName}
                        </span>
                    </div>
                    <button
                        onClick={onMobileClose}
                        className="rounded-md bg-slate-800 p-1.5 text-slate-400 hover:bg-slate-700 hover:text-white dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700 lg:hidden"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <nav className="space-y-1 p-3">
                    {MENU_ITEMS.map((item) => {
                        const isActive = url.startsWith(item.href);
                        return (
                            <Link
                                key={item.label}
                                href={item.href}
                                onClick={onMobileClose}
                                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                                    isActive
                                        ? 'bg-blue-600 text-white'
                                        : 'text-slate-400 hover:bg-slate-800 hover:text-white dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'
                                }`}
                            >
                                <item.icon className="size-5 shrink-0" />
                                <span className="truncate">{item.label}</span>
                            </Link>
                        );
                    })}
                </nav>
            </div>
            <div className="border-t border-slate-800 p-3 dark:border-zinc-800">
                <button
                    onClick={() => { router.post('/switch-to-client'); onMobileClose(); }}
                    className="flex w-full items-center gap-3 rounded-lg bg-slate-800 px-3 py-2.5 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                >
                    <RefreshCw className="size-4 shrink-0 text-slate-400 dark:text-zinc-400" />
                    <span>Режим клиента</span>
                </button>
                <button
                    onClick={handleLogout}
                    className="mt-2 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-xs font-semibold text-slate-400 transition-colors hover:bg-slate-800 hover:text-red-400 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-red-400"
                >
                    <LogOut className="size-4 shrink-0" />
                    <span>Выйти</span>
                </button>
                <div className="mt-3 text-center text-[10px] text-slate-600 dark:text-zinc-600">
                    v{props.appVersion || '1.0.0'}
                </div>
            </div>
        </div>
    );

    return (
        <>
            {/* Desktop: fixed left sidebar */}
            <aside className="fixed inset-y-0 left-0 z-40 hidden w-64 lg:block">
                {sidebarContent}
            </aside>
            {/* Mobile: overlay sidebar */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div className="fixed inset-0 bg-black/50" onClick={onMobileClose} />
                    <div className="relative z-10 h-full w-64">
                        {sidebarContent}
                    </div>
                </div>
            )}
        </>
    );
}
