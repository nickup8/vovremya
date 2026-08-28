import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays, Users, BarChart3, Settings, BookOpen,
    X, LogOut, RefreshCw, ChevronLeft, ChevronRight,
    Sun, Moon, type LucideIcon,
} from 'lucide-react';
import { useEffect, useCallback } from 'react';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/lib/utils';
import { useAppearance } from '@/hooks/use-appearance';

const MENU_ITEMS: { icon: LucideIcon; label: string; href: string; ownerOnly?: boolean }[] = [
    { icon: CalendarDays, label: 'Календарь', href: '/admin/calendar' },
    { icon: Users, label: 'Клиенты', href: '/admin/clients' },
    { icon: BookOpen, label: 'Услуги', href: '/admin/catalog' },
    { icon: BarChart3, label: 'Аналитика', href: '/admin/analytics' },
    { icon: Settings, label: 'Настройки', href: '/admin/settings' },
];

interface SidebarProps {
    collapsed: boolean;
    onToggleCollapse: () => void;
    mobileOpen: boolean;
    onMobileClose: () => void;
}

export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onMobileClose }: SidebarProps) {
    const { url, props } = usePage();
    const { appearance, updateAppearance } = useAppearance();

    const authUser = (props as { auth?: { user?: Record<string, unknown> } })?.auth?.user;
    const userName = (authUser?.name as string) || 'Мастер';
    const tariffName = (authUser?.tariff_name as string) || 'Free';
    const avatarUrl = (authUser?.avatar_url as string | null) ?? undefined;
    const initials = getInitials(userName);
    const canManageTeam = (authUser?.can_manage_team as boolean) ?? false;
    const canBilling = (authUser?.can_manage_billing as boolean) ?? false;

    const visibleMenuItems = MENU_ITEMS.filter(
        (item) => !item.ownerOnly || canManageTeam,
    );

    const handleLogout = useCallback(() => {
        router.post('/logout', {}, {
            onFinish: () => { window.location.href = '/'; },
        });
    }, []);

    // Escape closes mobile sidebar
    useEffect(() => {
        if (!mobileOpen) return;
        const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onMobileClose(); };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [mobileOpen, onMobileClose]);

    const toggleTheme = () => {
        const order: Array<'light' | 'dark' | 'system'> = ['light', 'dark', 'system'];
        const idx = order.indexOf(appearance);
        updateAppearance(order[(idx + 1) % order.length]);
    };

    const sidebarContent = (
        <div className="flex h-full flex-col">
            {/* Brand */}
            <div className={`flex h-[52px] items-center border-b px-2 pb-4 ${collapsed ? 'justify-center' : 'gap-2'}`}>
                {collapsed ? (
                    <img src="/images/logo-white.svg" alt="Вовремя" className="h-8 w-8 object-contain" style={{ filter: 'brightness(0) invert(1)' }} />
                ) : (
                    <img src="/images/logo-white.svg" alt="Вовремя" className="h-7 w-auto" style={{ filter: 'brightness(0) invert(1)' }} />
                )}
            </div>

            {/* Navigation */}
            <nav className="mt-3 flex flex-1 flex-col gap-1">
                {visibleMenuItems.map((item) => {
                    const isActive = url.startsWith(item.href);

                    return (
                        <Link
                            key={item.label}
                            href={item.href}
                            onClick={onMobileClose}
                            className={`relative flex h-11 w-full items-center gap-3 rounded-[10px] text-sm font-medium transition-colors ${
                                collapsed ? 'justify-center px-0' : 'px-3'
                            } ${
                                isActive
                                    ? 'bg-[var(--color-orange-100)] text-[var(--color-ink)] font-semibold'
                                    : 'text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]'
                            }`}
                            title={collapsed ? item.label : undefined}
                        >
                            {isActive && (
                                <span className="absolute left-0 top-[10px] h-6 w-[3px] rounded-full bg-[var(--color-orange)]" />
                            )}
                            <item.icon className={`size-5 shrink-0 ${isActive ? 'text-[var(--color-orange)]' : 'text-[#77736E]'}`} />
                            {!collapsed && <span className="truncate">{item.label}</span>}
                        </Link>
                    );
                })}
            </nav>

            {/* Spacer */}
            <div className="flex-1" />

            {/* Profile section */}
            <div className={`border-t border-[var(--color-line-soft)] pt-3 ${collapsed ? 'flex flex-col items-center gap-2' : ''}`}>
                <div className={`flex items-center ${collapsed ? 'flex-col gap-2' : 'gap-3'}`}>
                    <Avatar className="size-9 shrink-0">
                        <AvatarImage src={avatarUrl} alt={userName} className="object-cover" />
                        <AvatarFallback className="bg-[var(--color-line)] text-xs font-bold text-[var(--color-ink)]">
                            {initials}
                        </AvatarFallback>
                    </Avatar>
                    {!collapsed && (
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[13px] font-semibold text-[var(--color-ink)]">{userName}</p>
                            <p className="text-[11px] text-[var(--color-graphite)]">Тариф · {tariffName}</p>
                        </div>
                    )}
                </div>

                {/* Actions */}
                <div className={`mt-3 flex flex-col gap-1 ${collapsed ? 'items-center' : ''}`}>
                    {canBilling && (
                        <Link
                            href="/admin/billing"
                            onClick={onMobileClose}
                            className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] ${collapsed ? 'justify-center' : ''}`}
                            title={collapsed ? 'Тариф' : undefined}
                        >
                            <CreditCardIcon />
                            {!collapsed && <span>Тариф</span>}
                        </Link>
                    )}
                    <button
                        onClick={() => { onMobileClose(); router.post('/switch-to-client'); }}
                        className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] ${collapsed ? 'justify-center' : ''}`}
                        title={collapsed ? 'Режим клиента' : undefined}
                    >
                        <RefreshCw className="size-4 shrink-0" />
                        {!collapsed && <span>Режим клиента</span>}
                    </button>
                    <button
                        onClick={toggleTheme}
                        className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] ${collapsed ? 'justify-center' : ''}`}
                        title={collapsed ? (appearance === 'dark' ? 'Светлая тема' : 'Тёмная тема') : undefined}
                    >
                        {appearance === 'dark' ? <Sun className="size-4 shrink-0" /> : <Moon className="size-4 shrink-0" />}
                        {!collapsed && <span>{appearance === 'dark' ? 'Светлая тема' : 'Тёмная тема'}</span>}
                    </button>
                    <button
                        onClick={() => { onMobileClose(); handleLogout(); }}
                        className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-red-500 ${collapsed ? 'justify-center' : ''}`}
                        title={collapsed ? 'Выйти' : undefined}
                    >
                        <LogOut className="size-4 shrink-0" />
                        {!collapsed && <span>Выйти</span>}
                    </button>
                </div>

                {/* Version */}
                {!collapsed && (
                    <div className="mt-3 pb-1 text-center text-[10px] text-[var(--color-graphite)]">
                        v{(props as Record<string, unknown>).appVersion || '1.0.0'}
                    </div>
                )}
            </div>
        </div>
    );

    return (
        <>
            {/* Desktop sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-40 hidden flex-col border-r border-[var(--color-line)] bg-[var(--color-warm)] backdrop-blur-[18px] transition-[width] duration-200 lg:flex ${
                    collapsed ? 'w-[76px] p-[16px_10px]' : 'w-60 p-[20px_16px_16px]'
                }`}
                aria-label="Основная навигация"
            >
                {sidebarContent}
            </aside>

            {/* Desktop collapse toggle */}
            <button
                onClick={onToggleCollapse}
                className="fixed z-50 hidden items-center justify-center rounded-full border border-[var(--color-line)] bg-[var(--color-warm)] text-[var(--color-graphite)] shadow-sm transition-colors hover:bg-[var(--color-line-soft)] lg:flex"
                style={{
                    left: collapsed ? '64px' : '228px',
                    top: '50%',
                    width: '24px',
                    height: '24px',
                    transform: 'translateY(-50%)',
                }}
                aria-label={collapsed ? 'Развернуть боковую панель' : 'Сложить боковую панель'}
            >
                {collapsed ? <ChevronRight className="size-3" /> : <ChevronLeft className="size-3" />}
            </button>

            {/* Mobile overlay + sidebar */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="fixed inset-0 bg-black/40 backdrop-blur-[2px]"
                        onClick={onMobileClose}
                        aria-hidden="true"
                    />
                    <aside
                        className="relative z-10 flex h-full w-[min(288px,calc(100vw-48px))] flex-col border-r border-[var(--color-line)] bg-[var(--color-warm)] p-[20px_16px_16px]"
                        aria-label="Основная навигация"
                    >
                        <div className="flex h-[52px] items-center justify-between border-b px-2 pb-4">
                            <img src="/images/logo-white.svg" alt="Вовремя" className="h-7 w-auto" style={{ filter: 'brightness(0) invert(1)' }} />
                            <button
                                onClick={onMobileClose}
                                className="rounded-md p-1.5 text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)]"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                        <nav className="mt-3 flex flex-1 flex-col gap-1">
                            {visibleMenuItems.map((item) => {
                                const isActive = url.startsWith(item.href);

                                return (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        onClick={onMobileClose}
                                        className={`relative flex h-11 w-full items-center gap-3 rounded-[10px] px-3 text-sm font-medium transition-colors ${
                                            isActive
                                                ? 'bg-[var(--color-orange-100)] text-[var(--color-ink)] font-semibold'
                                                : 'text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]'
                                        }`}
                                    >
                                        {isActive && (
                                            <span className="absolute left-0 top-[10px] h-6 w-[3px] rounded-full bg-[var(--color-orange)]" />
                                        )}
                                        <item.icon className={`size-5 shrink-0 ${isActive ? 'text-[var(--color-orange)]' : 'text-[#77736E]'}`} />
                                        <span className="truncate">{item.label}</span>
                                    </Link>
                                );
                            })}
                        </nav>
                        <div className="flex-1" />
                        <div className="border-t border-[var(--color-line-soft)] pt-3">
                            <div className="flex items-center gap-3">
                                <Avatar className="size-9 shrink-0">
                                    <AvatarImage src={avatarUrl} alt={userName} className="object-cover" />
                                    <AvatarFallback className="bg-[var(--color-line)] text-xs font-bold text-[var(--color-ink)]">
                                        {initials}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] font-semibold text-[var(--color-ink)]">{userName}</p>
                                    <p className="text-[11px] text-[var(--color-graphite)]">Тариф · {tariffName}</p>
                                </div>
                            </div>
                            <div className="mt-3 flex flex-col gap-1">
                                <button
                                    onClick={() => { onMobileClose(); router.post('/switch-to-client'); }}
                                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                >
                                    <RefreshCw className="size-4 shrink-0" />
                                    <span>Режим клиента</span>
                                </button>
                                <button
                                    onClick={toggleTheme}
                                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                >
                                    {appearance === 'dark' ? <Sun className="size-4 shrink-0" /> : <Moon className="size-4 shrink-0" />}
                                    <span>{appearance === 'dark' ? 'Светлая тема' : 'Тёмная тема'}</span>
                                </button>
                                <button
                                    onClick={() => { onMobileClose(); handleLogout(); }}
                                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-red-500"
                                >
                                    <LogOut className="size-4 shrink-0" />
                                    <span>Выйти</span>
                                </button>
                            </div>
                        </div>
                    </aside>
                </div>
            )}
        </>
    );
}

function CreditCardIcon() {
    return (
        <svg className="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M3 10h18M7 15h4" />
        </svg>
    );
}
