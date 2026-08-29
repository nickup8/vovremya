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
    CreditCardIcon,
    EllipsisVerticalIcon,
    QuestionMarkCircleIcon,
    SunIcon,
    MoonIcon,
} from '@heroicons/react/24/outline';
import { useState, useEffect, useCallback, type ComponentType } from 'react';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/lib/utils';
import { useAppearance } from '@/hooks/use-appearance';

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
    onToggleTheme?: () => void;
}

export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onMobileClose, onToggleTheme }: SidebarProps) {
    const { url, props } = usePage();
    const { resolvedAppearance } = useAppearance();
    const [profileMenuOpen, setProfileMenuOpen] = useState(false);

    const authUser = (props as { auth?: { user?: Record<string, unknown> } })?.auth?.user;
    const userName = (authUser?.name as string) || 'Мастер';
    const tariffName = (authUser?.tariff_name as string) || 'Free';
    const avatarUrl = (authUser?.avatar_url as string | null) ?? undefined;
    const initials = getInitials(userName);
    const canManageTeam = (authUser?.can_manage_team as boolean) ?? false;
    const canBilling = (authUser?.can_manage_billing as boolean) ?? false;

    const isDark = resolvedAppearance === 'dark';
    const logoFull = isDark ? '/images/logo-white.svg' : '/images/logo.svg';
    const logoMark = isDark ? '/images/logo-mark-white.svg' : '/images/logo-mark.svg';

    const filterVisible = (items: NavItem[]) => items.filter((item) => !item.ownerOnly || canManageTeam);

    const handleLogout = useCallback(() => {
        router.post('/logout', {}, {
            onFinish: () => { window.location.href = '/'; },
        });
    }, []);

    // Escape closes mobile sidebar and profile menu
    useEffect(() => {
        if (!mobileOpen && !profileMenuOpen) return;
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                if (profileMenuOpen) setProfileMenuOpen(false);
                else if (mobileOpen) onMobileClose();
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [mobileOpen, profileMenuOpen, onMobileClose]);

    // Close profile menu on outside click
    useEffect(() => {
        if (!profileMenuOpen) return;
        const handler = () => setProfileMenuOpen(false);
        // Delay to avoid the opening click immediately closing it
        const timer = setTimeout(() => document.addEventListener('click', handler), 0);
        return () => { clearTimeout(timer); document.removeEventListener('click', handler); };
    }, [profileMenuOpen]);

    const toggleProfile = (e: React.MouseEvent) => {
        e.stopPropagation();
        setProfileMenuOpen((prev) => !prev);
    };

    const closeProfile = () => setProfileMenuOpen(false);

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

    const profileDropdown = profileMenuOpen ? (
        <div
            className="absolute bottom-full left-0 mb-1 w-56 rounded-xl border border-[var(--color-line)] bg-white p-1.5 shadow-lg dark:bg-zinc-900"
            onClick={(e) => e.stopPropagation()}
            role="menu"
        >
            {canBilling && (
                <Link
                    href="/admin/billing"
                    onClick={() => { closeProfile(); onMobileClose(); }}
                    className="flex h-10 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                    role="menuitem"
                >
                    <CreditCardIcon className="size-[18px] text-[var(--color-graphite)]" />
                    <span>Тариф · {tariffName}</span>
                </Link>
            )}
            <button
                onClick={() => { closeProfile(); onMobileClose(); router.post('/switch-to-client'); }}
                className="flex h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                role="menuitem"
            >
                <ArrowPathIcon className="size-[18px] text-[var(--color-graphite)]" />
                <span>Режим клиента</span>
            </button>
            <div className="mx-1 my-1 h-px bg-[var(--color-line-soft)]" />
            <button
                onClick={() => { closeProfile(); onMobileClose(); handleLogout(); }}
                className="flex h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)]"
                role="menuitem"
            >
                <ArrowRightStartOnRectangleIcon className="size-[18px] text-[var(--color-graphite)]" />
                <span>Выйти</span>
            </button>
        </div>
    ) : null;

    const sidebarContent = (
        <div className="flex h-full flex-col">
            {/* Brand */}
            <div className={`flex h-[52px] items-center border-b border-[var(--color-line-soft)] px-2 pb-4 ${collapsed ? 'justify-center' : ''}`}>
                <img
                    src={collapsed ? logoMark : logoFull}
                    alt="Вовремя"
                    className={collapsed ? 'h-8 w-8 object-contain' : 'h-7 w-auto'}
                />
            </div>

            {/* Navigation */}
            <nav className="mt-3 flex flex-1 flex-col gap-1">
                {!collapsed && (
                    <div className="px-3 pt-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                        Работа
                    </div>
                )}
                {renderNavItems(WORK_ITEMS, false)}

                {!collapsed && (
                    <div className="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                        Система
                    </div>
                )}
                {renderNavItems(SYSTEM_ITEMS, false)}
            </nav>

            {/* Spacer */}
            <div className="flex-1" />

            {/* Profile section */}
            <div className="border-t border-[var(--color-line-soft)] pt-3">
                <div className={`flex items-center ${collapsed ? 'justify-center' : 'gap-2.5'}`}>
                    {/* Avatar — always clickable to open profile menu */}
                    <button
                        onClick={toggleProfile}
                        className="shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-orange)]"
                        aria-label="Меню профиля"
                        aria-expanded={profileMenuOpen}
                        aria-controls="profile-menu"
                    >
                        <Avatar className="size-9">
                            <AvatarImage src={avatarUrl} alt={userName} className="object-cover" />
                            <AvatarFallback className="bg-[var(--color-line)] text-xs font-bold text-[var(--color-ink)]">
                                {initials}
                            </AvatarFallback>
                        </Avatar>
                    </button>
                    {!collapsed && (
                        <>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px] font-semibold text-[var(--color-ink)]">{userName}</p>
                                <p className="text-[11px] text-[var(--color-graphite)]">Тариф · {tariffName}</p>
                            </div>
                            <button
                                onClick={toggleProfile}
                                className="flex size-8 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                aria-label="Меню профиля"
                                aria-expanded={profileMenuOpen}
                            >
                                <EllipsisVerticalIcon className="size-4" />
                            </button>
                        </>
                    )}
                    {/* Dropdown positioned relative to the profile row */}
                    {profileMenuOpen && (
                        <div className="relative">
                            {profileDropdown}
                        </div>
                    )}
                </div>

                {/* Version */}
                {!collapsed && (
                    <div className="mt-2 pb-0.5 text-center text-[9px] text-[var(--color-graphite)]/50">
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
                className={`fixed inset-y-0 left-0 z-40 hidden flex-col border-r border-[var(--color-line)] bg-white/88 backdrop-blur-[18px] transition-[width] duration-200 dark:bg-[var(--color-cal-sidebar)] lg:flex ${
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
                        className="relative z-10 flex h-[100dvh] w-[min(320px,calc(100vw-48px))] flex-col overflow-hidden border-r border-[var(--color-line)] bg-white/88 dark:bg-[var(--color-cal-sidebar)]"
                        aria-label="Основная навигация"
                    >
                        <div className="flex-none px-4 pt-5 pb-3">
                            <div className="flex h-[52px] items-center justify-between border-b border-[var(--color-line-soft)] px-2 pb-4">
                                <img src={logoFull} alt="Вовремя" className="h-7 w-auto" />
                                <button
                                    onClick={onMobileClose}
                                    className="flex size-10 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                                    aria-label="Закрыть меню"
                                >
                                    <XMarkIcon className="size-5" />
                                </button>
                            </div>
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-[max(16px,env(safe-area-inset-bottom))]">
                            <nav className="flex flex-col gap-1">
                                <div className="px-3 pt-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                                    Работа
                                </div>
                                {renderNavItems(WORK_ITEMS, true)}
                                <div className="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--color-graphite)]">
                                    Система
                                </div>
                                {renderNavItems(SYSTEM_ITEMS, true)}
                            </nav>
                            <div className="border-t border-[var(--color-line-soft)] pt-3 mt-3">
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
                                            className="flex h-11 items-center gap-3 rounded-[10px] px-3 text-sm font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]"
                                        >
                                            <CreditCardIcon className="size-5 shrink-0 text-[#77736E]" />
                                            <span>Тариф и оплата</span>
                                        </Link>
                                    )}
                                    <button
                                        onClick={() => { onMobileClose(); router.post('/switch-to-client'); }}
                                        className="flex h-11 items-center gap-3 rounded-[10px] px-3 text-sm font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]"
                                    >
                                        <ArrowPathIcon className="size-5 shrink-0 text-[#77736E]" />
                                        <span>Режим клиента</span>
                                    </button>
                                    {onToggleTheme && (
                                        <button
                                            onClick={onToggleTheme}
                                            className="flex h-11 items-center gap-3 rounded-[10px] px-3 text-sm font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]"
                                        >
                                            {isDark ? (
                                                <>
                                                    <SunIcon className="size-5 shrink-0 text-[#77736E]" />
                                                    <span>Светлая тема</span>
                                                </>
                                            ) : (
                                                <>
                                                    <MoonIcon className="size-5 shrink-0 text-[#77736E]" />
                                                    <span>Тёмная тема</span>
                                                </>
                                            )}
                                        </button>
                                    )}
                                    <div className="mx-1 my-1 h-px bg-[var(--color-line-soft)]" />
                                    <button
                                        onClick={() => { onMobileClose(); handleLogout(); }}
                                        className="flex h-11 items-center gap-3 rounded-[10px] px-3 text-sm font-medium text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]"
                                    >
                                        <ArrowRightStartOnRectangleIcon className="size-5 shrink-0 text-[#77736E]" />
                                        <span>Выйти</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            )}
        </>
    );
}
