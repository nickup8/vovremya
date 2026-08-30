import { useState, useEffect, useCallback } from 'react';
import { usePage, Link } from '@inertiajs/react';
import {
    Bars3Icon, BellIcon, PlusIcon,
    SunIcon, MoonIcon,
} from '@heroicons/react/24/outline';
import { PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import type { ReactNode } from 'react';
import Sidebar from '@/components/admin/Sidebar';
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/hooks/use-appearance';

const SIDEBAR_COLLAPSED_KEY = 'vovremya-sidebar-collapsed';
const LG_BREAKPOINT = 1024;

function useIsDesktop() {
    const [isDesktop, setIsDesktop] = useState(() =>
        typeof window !== 'undefined' ? window.innerWidth >= LG_BREAKPOINT : true,
    );
    useEffect(() => {
        const mql = window.matchMedia(`(min-width: ${LG_BREAKPOINT}px)`);
        const handler = (e: MediaQueryListEvent) => setIsDesktop(e.matches);
        mql.addEventListener('change', handler);
        return () => mql.removeEventListener('change', handler);
    }, []);
    return isDesktop;
}

interface AdminLayoutProps {
    children: ReactNode;
    title: string;
    auth?: { user?: Record<string, unknown> };
    headerActions?: ReactNode;
    todayCount?: number | null;
    fullBleed?: boolean;
    hideNewAppointment?: boolean;
}

export default function AdminLayout({ children, title, auth, headerActions, todayCount, fullBleed, hideNewAppointment }: AdminLayoutProps) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
        try { return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'; } catch { return false; }
    });
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const { appearance, updateAppearance } = useAppearance();

    const isDark = appearance === 'dark';
    const isDesktop = useIsDesktop();

    const toggleCollapse = useCallback(() => {
        setSidebarCollapsed((prev) => {
            const next = !prev;
            try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0'); } catch {}
            return next;
        });
    }, []);

    const toggleTheme = () => {
        updateAppearance(isDark ? 'light' : 'dark');
    };

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
        const timer = setTimeout(() => document.addEventListener('click', handler), 0);
        return () => { clearTimeout(timer); document.removeEventListener('click', handler); };
    }, [notificationsOpen]);

    return (
        <div className="flex h-screen overflow-hidden bg-white text-[var(--color-ink)] dark:bg-[var(--color-cal-workspace)]">
            <Sidebar
                collapsed={sidebarCollapsed}
                onToggleCollapse={toggleCollapse}
                mobileOpen={mobileMenuOpen}
                onMobileClose={() => setMobileMenuOpen(false)}
                onToggleTheme={toggleTheme}
            />

            {/* Main workspace */}
            <div
                className={`flex min-w-0 flex-1 flex-col transition-[margin-left] duration-200 ${
                    sidebarCollapsed ? 'lg:ml-[76px]' : 'lg:ml-60'
                }`}
            >
                {/* Topbar */}
                <header className="relative z-50 flex h-[64px] shrink-0 items-center gap-3 border-b border-[var(--color-line)] bg-white/90 px-4 backdrop-blur-[16px] dark:bg-[var(--color-cal-topbar)] lg:h-[72px] lg:gap-4 lg:px-[28px]">
                    {/* Hamburger — mobile only */}
                    <button
                        onClick={() => setMobileMenuOpen(true)}
                        className="flex size-10 shrink-0 items-center justify-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] lg:hidden"
                        aria-label="Открыть меню"
                    >
                        <Bars3Icon className="size-5" />
                    </button>

                    {/* Desktop sidebar collapse toggle */}
                    <button
                        onClick={toggleCollapse}
                        className="hidden items-center justify-center rounded-[10px] p-2 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] lg:flex"
                        aria-label={sidebarCollapsed ? 'Развернуть боковую панель' : 'Сложить боковую панель'}
                        aria-pressed={sidebarCollapsed}
                    >
                        {sidebarCollapsed
                            ? <PanelLeftOpen className="size-[18px]" strokeWidth={1.75} />
                            : <PanelLeftClose className="size-[18px]" strokeWidth={1.75} />
                        }
                    </button>

                    {/* Title block */}
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-[21px] font-bold leading-7 tracking-[-.035em] text-[var(--color-ink)] lg:text-[25px] lg:leading-8">
                            {title}
                        </h1>
                        {/* Today context — inline on desktop, under title on mobile */}
                        {todayCount !== null && todayCount !== undefined && (
                            <div className="flex items-center gap-1.5 text-[11px] leading-4 text-[var(--color-graphite)] lg:hidden">
                                <span className="inline-block size-1.5 rounded-full bg-[var(--color-orange)]" />
                                <span>Сегодня</span>
                                <span className="font-semibold">·</span>
                                <span className="font-semibold">{todayCount} {todayCount === 1 ? 'запись' : todayCount < 5 ? 'записи' : 'записей'}</span>
                            </div>
                        )}
                        {todayCount !== null && todayCount !== undefined && (
                            <div className="hidden items-center gap-2 text-xs text-[var(--color-graphite)] lg:flex">
                                <span className="inline-block size-1.5 rounded-full bg-[var(--color-orange)]" />
                                <span>Сегодня</span>
                                <span className="font-semibold text-[var(--color-graphite)]">·</span>
                                <span className="font-semibold text-[var(--color-graphite)]">{todayCount} {todayCount === 1 ? 'запись' : todayCount < 5 ? 'записи' : 'записей'}</span>
                            </div>
                        )}
                    </div>

                    {/* Actions */}
                    <div className="flex shrink-0 items-center gap-1 lg:gap-2">
                        {/* Theme switch — desktop only */}
                        <button
                            onClick={toggleTheme}
                            className="hidden h-10 w-[72px] place-items-center rounded-[10px] border-0 bg-transparent transition-colors hover:bg-[var(--color-line-soft)] lg:grid"
                            aria-label={isDark ? 'Переключить на светлую тему' : 'Переключить на тёмную тему'}
                            title={isDark ? 'Тёмная тема' : 'Светлая тема'}
                        >
                            <span className="relative flex h-[28px] w-[56px] items-center rounded-full border border-[var(--color-line)] bg-[var(--color-warm)]">
                                <span className={`relative z-10 flex w-1/2 items-center justify-center transition-colors ${isDark ? 'text-[var(--color-graphite)]' : 'text-[var(--color-ink)]'}`}>
                                    <SunIcon className="size-3.5" />
                                </span>
                                <span className={`relative z-10 flex w-1/2 items-center justify-center transition-colors ${isDark ? 'text-[var(--color-ink)]' : 'text-[var(--color-graphite)]'}`}>
                                    <MoonIcon className="size-3.5" />
                                </span>
                                <span
                                    className={`absolute left-[3px] top-[3px] size-5 rounded-full bg-white shadow-sm transition-transform duration-200 dark:bg-zinc-800 ${
                                        isDark ? 'translate-x-[28px]' : ''
                                    }`}
                                />
                            </span>
                        </button>

                        {/* Notifications — always visible */}
                        <div className="relative">
                            <button
                                onClick={(e) => { e.stopPropagation(); setNotificationsOpen(!notificationsOpen); }}
                                className="flex size-10 items-center justify-center rounded-[10px] border border-transparent text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                aria-label="Уведомления"
                                aria-expanded={notificationsOpen}
                            >
                                <BellIcon className="size-5" />
                            </button>
                            {/* Desktop popover — only mounted on desktop */}
                            {notificationsOpen && isDesktop && (
                                <section
                                    className="absolute right-0 top-[48px] z-[80] w-[380px] overflow-hidden rounded-2xl border border-[var(--color-line)] bg-white shadow-[0_18px_50px_rgba(24,24,24,0.14),0_3px_12px_rgba(24,24,24,0.08)] dark:bg-[var(--color-cal-surface)] dark:border-[var(--color-cal-border)]"
                                    onClick={(e) => e.stopPropagation()}
                                    aria-label="Уведомления"
                                >
                                    <div className="flex items-center justify-between gap-4 border-b border-[var(--color-line-soft)] px-4 py-[14px]">
                                        <div>
                                            <div className="text-[15px] leading-5 font-bold tracking-[-0.015em] text-[var(--color-ink)]">Уведомления</div>
                                            <div className="mt-px text-[11px] leading-4 text-[var(--color-graphite)]">Нет новых</div>
                                        </div>
                                        <button
                                            disabled
                                            className="shrink-0 rounded-[7px] px-1 py-1.5 text-[12px] leading-[18px] font-semibold text-[var(--color-graphite)]"
                                        >
                                            Всё прочитано
                                        </button>
                                    </div>
                                    <div className="px-4 py-5 text-center text-[13px] text-[var(--color-graphite)]">
                                        Нет уведомлений
                                    </div>
                                </section>
                            )}
                        </div>

                        {/* Page-specific CTA — always rightmost */}
                        {headerActions}

                        {/* New appointment — hidden when page provides its own primary CTA */}
                        {!hideNewAppointment && (
                            <Link
                                href="/admin/calendar"
                                className="flex size-10 items-center justify-center rounded-[10px] bg-[var(--color-orange)] text-white shadow-sm transition-colors hover:bg-[var(--color-orange-600)] lg:h-10 lg:w-auto lg:gap-2 lg:px-3.5 lg:text-sm lg:font-semibold"
                                aria-label="Новая запись"
                            >
                                <PlusIcon className="size-4" />
                                <span className="hidden lg:inline">Новая запись</span>
                            </Link>
                        )}
                    </div>
                </header>

                {/* Mobile notifications drawer — only mounted on mobile */}
                {notificationsOpen && !isDesktop && (
                    <div className="fixed inset-0 z-[60]">
                        <div className="fixed inset-0 bg-black/30" onClick={() => setNotificationsOpen(false)} aria-hidden="true" />
                        <div className="fixed inset-y-0 right-0 z-10 flex w-full flex-col bg-white dark:bg-[var(--color-cal-surface)]">
                            <div className="flex items-center justify-between border-b border-[var(--color-line)] px-4 py-4">
                                <div>
                                    <h2 className="text-[16px] font-bold text-[var(--color-ink)]">Уведомления</h2>
                                    <p className="text-[11px] text-[var(--color-graphite)]">Нет новых</p>
                                </div>
                                <button
                                    onClick={() => setNotificationsOpen(false)}
                                    className="flex size-10 items-center justify-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                >
                                    <svg className="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div className="flex flex-1 items-center justify-center text-[13px] text-[var(--color-graphite)]">
                                Нет уведомлений
                            </div>
                        </div>
                    </div>
                )}

                {/* Page content */}
                <main className={`flex-1 overflow-y-auto bg-white dark:bg-[var(--color-cal-workspace)] ${fullBleed ? '' : 'p-4 md:p-6'}`}>
                    {children}
                </main>
            </div>
            <Toaster />
        </div>
    );
}
