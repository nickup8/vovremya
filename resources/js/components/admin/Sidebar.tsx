import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDaysIcon,
    UsersIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    BookOpenIcon,
    XMarkIcon,
    ArrowRightStartOnRectangleIcon,
    ArrowPathIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    CreditCardIcon,
    EllipsisVerticalIcon,
    QuestionMarkCircleIcon,
} from '@heroicons/react/24/outline';
import { useState, useEffect, useCallback, type ComponentType } from 'react';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/lib/utils';

type HeroiconProps = { className?: string };

interface NavItem {
    icon: ComponentType<HeroiconProps>;
    label: string;
    href: string;
    ownerOnly?: boolean;
}

const WORK_ITEMS: NavItem[] = [
    { icon: CalendarDaysIcon, label: 'Календарь', href: '/admin/calendar' },
    { icon: UsersIcon, label: 'Клиенты', href: '/admin/clients' },
    { icon: BookOpenIcon, label: 'Услуги', href: '/admin/catalog' },
    { icon: ChartBarIcon, label: 'Аналитика', href: '/admin/analytics' },
];

const SYSTEM_ITEMS: NavItem[] = [
    { icon: Cog6ToothIcon, label: 'Настройки', href: '/admin/settings' },
    { icon: QuestionMarkCircleIcon, label: 'Помощь', href: '/admin/help' },
];

interface SidebarProps {
    collapsed: boolean;
    onToggleCollapse: () => void;
    mobileOpen: boolean;
    onMobileClose: () => void;
}

export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onMobileClose }: SidebarProps) {
    const { url, props } = usePage();
    const [profileMenuOpen, setProfileMenuOpen] = useState(false);

    const authUser = (props as { auth?: { user?: Record<string, unknown> } })?.auth?.user;
    const userName = (authUser?.name as string) || 'Мастер';
    const tariffName = (authUser?.tariff_name as string) || 'Free';
    const avatarUrl = (authUser?.avatar_url as string | null) ?? undefined;
    const initials = getInitials(userName);
    const canManageTeam = (authUser?.can_manage_team as boolean) ?? false;
    const canBilling = (authUser?.can_manage_billing as boolean) ?? false;

    const filterVisible = (items: NavItem[]) => items.filter((item) => !item.ownerOnly || canManageTeam);

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

    // Close profile menu on outside click
    useEffect(() => {
        if (!profileMenuOpen) return;
        const handler = () => setProfileMenuOpen(false);
        document.addEventListener('click', handler);
        return () => document.removeEventListener('click', handler);
    }, [profileMenuOpen]);

    const isDark = false; // logo selection handled by theme-aware img
    const logoSrc = '/images/logo.svg';

    const renderNavItems = (items: NavItem[], showLabels: boolean) =>
        filterVisible(items).map((item) => {
            const isActive = url.startsWith(item.href);

            return (
                <Link
                    key={item.label}
                    href={item.href}
                    onClick={onMobileClose}
                    className={`relative flex h-11 w-full items-center gap-3 rounded-[10px] text-sm font-medium transition-colors ${
                        collapsed && !showLabels ? 'justify-center px-0' : 'px-3'
                    } ${
                        isActive
                            ? 'bg-[var(--color-orange-100)] text-[var(--color-ink)] font-semibold'
                            : 'text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]'
                    }`}
                    title={collapsed && !showLabels ? item.label : undefined}
                >
                    {isActive && (
                        <span className="absolute left-0 top-[10px] h-6 w-[3px] rounded-full bg-[var(--color-orange)]" />
                    )}
                    <item.icon className={`size-5 shrink-0 ${isActive ? 'text-[var(--color-orange)]' : 'text-[#77736E]'}`} />
                    {(showLabels || !collapsed) && <span className="truncate">{item.label}</span>}
                </Link>
            );
        });

    const sidebarContent = (
        <div className="flex h-full flex-col">
            {/* Brand */}
            <div className={`flex h-[52px] items-center border-b border-[var(--color-line-soft)] px-2 pb-4 ${collapsed ? 'justify-center' : ''}`}>
                {collapsed ? (
                    <img src={logoSrc} alt="Вовремя" className="h-8 w-8 object-contain" />
                ) : (
                    <img src={logoSrc} alt="Вовремя" className="h-7 w-auto" />
                )}
            </div>

            {/* Navigation */}
            <nav className="mt-3 flex flex-1 flex-col gap-1">
                {/* РАБОТА section */}
                {!collapsed && (
                    <div className="px-3 pt-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                        Работа
                    </div>
                )}
                {renderNavItems(WORK_ITEMS, false)}

                {/* СИСТЕМА section */}
                {!collapsed && (
                    <div className="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                        Система
                    </div>
                )}
                {renderNavItems(SYSTEM_ITEMS, false)}
            </nav>

            {/* Spacer */}
            <div className="flex-1" />

            {/* Profile section — compact */}
            <div className="border-t border-[var(--color-line-soft)] pt-3">
                <div className={`flex items-center ${collapsed ? 'justify-center' : 'gap-2.5'}`}>
                    <Avatar className="size-9 shrink-0">
                        <AvatarImage src={avatarUrl} alt={userName} className="object-cover" />
                        <AvatarFallback className="bg-[var(--color-line)] text-xs font-bold text-[var(--color-ink)]">
                            {initials}
                        </AvatarFallback>
                    </Avatar>
                    {!collapsed && (
                        <>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px] font-semibold text-[var(--color-ink)]">{userName}</p>
                                <p className="text-[11px] text-[var(--color-graphite)]">Тариф · {tariffName}</p>
                            </div>
                            <div className="relative">
                                <button
                                    onClick={(e) => { e.stopPropagation(); setProfileMenuOpen(!profileMenuOpen); }}
                                    className="flex size-8 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                    aria-label="Меню профиля"
                                    aria-expanded={profileMenuOpen}
                                >
                                    <EllipsisVerticalIcon className="size-4" />
                                </button>
                                {profileMenuOpen && (
                                    <div
                                        className="absolute bottom-full left-0 mb-1 w-56 rounded-xl border border-[var(--color-line)] bg-white p-1.5 shadow-lg dark:bg-zinc-900"
                                        onClick={(e) => e.stopPropagation()}
                                        role="menu"
                                    >
                                        {canBilling && (
                                            <Link
                                                href="/admin/billing"
                                                onClick={() => { setProfileMenuOpen(false); onMobileClose(); }}
                                                className="flex h-10 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                                role="menuitem"
                                            >
                                                <CreditCardIcon className="size-[18px] text-[var(--color-graphite)]" />
                                                <span>Тариф · {tariffName}</span>
                                            </Link>
                                        )}
                                        <button
                                            onClick={() => { setProfileMenuOpen(false); onMobileClose(); router.post('/switch-to-client'); }}
                                            className="flex h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                            role="menuitem"
                                        >
                                            <ArrowPathIcon className="size-[18px] text-[var(--color-graphite)]" />
                                            <span>Режим клиента</span>
                                        </button>
                                        <div className="mx-1 my-1 h-px bg-[var(--color-line-soft)]" />
                                        <button
                                            onClick={() => { setProfileMenuOpen(false); onMobileClose(); handleLogout(); }}
                                            className="flex h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                            role="menuitem"
                                        >
                                            <ArrowRightStartOnRectangleIcon className="size-[18px] text-[var(--color-graphite)]" />
                                            <span>Выйти</span>
                                        </button>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
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
                        <div className="flex h-[52px] items-center justify-between border-b border-[var(--color-line-soft)] px-2 pb-4">
                            <img src={logoSrc} alt="Вовремя" className="h-7 w-auto" />
                            <button
                                onClick={onMobileClose}
                                className="rounded-md p-1.5 text-[var(--color-graphite)] hover:bg-[var(--color-line-soft)]"
                            >
                                <XMarkIcon className="size-4" />
                            </button>
                        </div>
                        <nav className="mt-3 flex flex-1 flex-col gap-1">
                            <div className="px-3 pt-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                                Работа
                            </div>
                            {renderNavItems(WORK_ITEMS, true)}
                            <div className="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                                Система
                            </div>
                            {renderNavItems(SYSTEM_ITEMS, true)}
                        </nav>
                        <div className="flex-1" />
                        <div className="border-t border-[var(--color-line-soft)] pt-3">
                            <div className="flex items-center gap-2.5">
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
                                {canBilling && (
                                    <Link
                                        href="/admin/billing"
                                        onClick={onMobileClose}
                                        className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                    >
                                        <CreditCardIcon className="size-[18px] text-[var(--color-graphite)]" />
                                        <span>Тариф · {tariffName}</span>
                                    </Link>
                                )}
                                <button
                                    onClick={() => { onMobileClose(); router.post('/switch-to-client'); }}
                                    className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                >
                                    <ArrowPathIcon className="size-[18px] text-[var(--color-graphite)]" />
                                    <span>Режим клиента</span>
                                </button>
                                <div className="mx-1 my-1 h-px bg-[var(--color-line-soft)]" />
                                <button
                                    onClick={() => { onMobileClose(); handleLogout(); }}
                                    className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                                >
                                    <ArrowRightStartOnRectangleIcon className="size-[18px] text-[var(--color-graphite)]" />
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
