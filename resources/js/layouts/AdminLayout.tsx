import { useState, useEffect, useCallback } from 'react';
import { usePage, Link } from '@inertiajs/react';
import { Bars3Icon, BellIcon, PlusIcon } from '@heroicons/react/24/outline';
import type { ReactNode } from 'react';
import Sidebar from '@/components/admin/Sidebar';

const SIDEBAR_COLLAPSED_KEY = 'vovremya-sidebar-collapsed';

interface AdminLayoutProps {
    children: ReactNode;
    title: string;
    auth?: { user?: Record<string, unknown> };
    headerActions?: ReactNode;
}

export default function AdminLayout({ children, title, auth, headerActions }: AdminLayoutProps) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
        try { return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'; } catch { return false; }
    });
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const { props } = usePage();

    const toggleCollapse = useCallback(() => {
        setSidebarCollapsed((prev) => {
            const next = !prev;
            try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0'); } catch {}
            return next;
        });
    }, []);

    // Close mobile menu on resize
    useEffect(() => {
        const handler = () => { if (window.innerWidth > 1024) setMobileMenuOpen(false); };
        window.addEventListener('resize', handler);
        return () => window.removeEventListener('resize', handler);
    }, []);

    // Close notifications on Escape
    useEffect(() => {
        if (!notificationsOpen) return;
        const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') setNotificationsOpen(false); };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [notificationsOpen]);

    // Close notifications on outside click
    useEffect(() => {
        if (!notificationsOpen) return;
        const handler = () => setNotificationsOpen(false);
        document.addEventListener('click', handler);
        return () => document.removeEventListener('click', handler);
    }, [notificationsOpen]);

    return (
        <div className="flex h-screen overflow-hidden bg-[var(--color-warm)] text-[var(--color-ink)]">
            <Sidebar
                collapsed={sidebarCollapsed}
                onToggleCollapse={toggleCollapse}
                mobileOpen={mobileMenuOpen}
                onMobileClose={() => setMobileMenuOpen(false)}
            />

            {/* Main workspace */}
            <div
                className={`flex min-w-0 flex-1 flex-col transition-[margin-left] duration-200 ${
                    sidebarCollapsed ? 'lg:ml-[76px]' : 'lg:ml-60'
                }`}
            >
                {/* Topbar */}
                <header className="flex h-[72px] shrink-0 items-center gap-4 border-b border-[var(--color-line)] bg-white/90 px-6 backdrop-blur-[16px] dark:bg-zinc-900/90">
                    {/* Mobile menu button */}
                    <button
                        onClick={() => setMobileMenuOpen(true)}
                        className="rounded-lg p-2 text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)] lg:hidden"
                        aria-label="Открыть меню"
                    >
                        <Bars3Icon className="size-5" />
                    </button>

                    {/* Page title */}
                    <h1 className="text-[25px] font-bold leading-8 tracking-[-.035em] text-[var(--color-ink)]">
                        {title}
                    </h1>

                    {/* Spacer */}
                    <div className="flex-1" />

                    {/* Actions */}
                    <div className="flex items-center gap-2">
                        {headerActions}

                        {/* Notifications shell */}
                        <div className="relative">
                            <button
                                onClick={(e) => { e.stopPropagation(); setNotificationsOpen(!notificationsOpen); }}
                                className="flex size-10 items-center justify-center rounded-[10px] border border-transparent text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                aria-label="Уведомления"
                                aria-expanded={notificationsOpen}
                            >
                                <BellIcon className="size-5" />
                            </button>
                            {notificationsOpen && (
                                <section
                                    className="absolute right-0 top-12 z-[80] w-[380px] max-h-[min(560px,calc(100vh-92px))] overflow-hidden rounded-2xl border border-[var(--color-line)] bg-white shadow-lg dark:bg-zinc-900"
                                    onClick={(e) => e.stopPropagation()}
                                    aria-label="Уведомления"
                                >
                                    <div className="flex min-h-[68px] items-center justify-between border-b border-[var(--color-line-soft)] px-4 py-3.5">
                                        <div>
                                            <div className="text-[15px] font-bold tracking-tight">Уведомления</div>
                                            <div className="text-[11px] text-[var(--color-graphite)]">Нет новых</div>
                                        </div>
                                        <button
                                            disabled
                                            className="rounded-md px-1 py-1.5 text-xs font-semibold text-[var(--color-graphite)]"
                                        >
                                            Всё прочитано
                                        </button>
                                    </div>
                                    <div className="flex items-center justify-center py-12 text-sm text-[var(--color-graphite)]">
                                        Нет уведомлений
                                    </div>
                                </section>
                            )}
                        </div>

                        {/* New appointment button */}
                        <Link
                            href="/admin/calendar"
                            className="flex h-10 items-center gap-2 rounded-[10px] bg-[var(--color-orange)] px-3.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[var(--color-orange-600)]"
                        >
                            <PlusIcon className="size-4" />
                            <span className="hidden sm:inline">Новая запись</span>
                        </Link>
                    </div>
                </header>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto bg-white p-4 md:p-6 dark:bg-zinc-900">
                    {children}
                </main>
            </div>
        </div>
    );
}
