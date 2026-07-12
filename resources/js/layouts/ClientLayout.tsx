import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { Sun, Moon, LogOut, User, CalendarDays, Home } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';

interface ClientLayoutProps {
    children: ReactNode;
}

function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();

    const cycle = () => {
        const order: Array<'light' | 'dark' | 'system'> = ['light', 'dark', 'system'];
        const idx = order.indexOf(appearance);
        updateAppearance(order[(idx + 1) % order.length]);
    };

    return (
        <button
            onClick={cycle}
            className="rounded-full p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
        >
            {appearance === 'light' && <Sun className="size-5" />}
            {appearance === 'dark' && <Moon className="size-5" />}
            {appearance === 'system' && <Sun className="size-5" />}
        </button>
    );
}

export default function ClientLayout({ children }: ClientLayoutProps) {
    const { url, props } = usePage();
    const clientName = (props as { client?: { name?: string } })?.client?.name ?? 'Клиент';

    function handleLogout() {
        router.post('/client/logout', {}, {
            onFinish: () => {
                window.location.href = '/';
            },
        });
    }

    const navItems = [
        { href: '/client/my-profile', label: 'Главная', icon: Home },
        { href: '/client/my-bookings', label: 'Записи', icon: CalendarDays },
    ];

    return (
        <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-50">
            {/* Header */}
            <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div className="flex items-center gap-3">
                    <div className="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-bold text-white">
                        {clientName.charAt(0).toUpperCase()}
                    </div>
                    <span className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                        {clientName}
                    </span>
                </div>
                <div className="flex items-center gap-1">
                    <ThemeToggle />
                    <button
                        onClick={handleLogout}
                        className="rounded-full p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-red-500 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-red-400"
                    >
                        <LogOut className="size-5" />
                    </button>
                </div>
            </header>

            {/* Main content */}
            <main className="flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </div>
            </main>

            {/* Bottom Navigation (mobile) */}
            <nav className="fixed inset-x-0 bottom-0 z-30 flex border-t border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 lg:hidden">
                {navItems.map((item) => {
                    const isActive = url.startsWith(item.href);
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`flex flex-1 flex-col items-center gap-0.5 py-2 text-[10px] font-medium transition-colors ${
                                isActive
                                    ? 'text-blue-600 dark:text-blue-400'
                                    : 'text-slate-500 hover:text-slate-700 dark:text-zinc-500 dark:hover:text-zinc-300'
                            }`}
                        >
                            <item.icon className={`size-5 ${isActive ? 'text-blue-600 dark:text-blue-400' : ''}`} />
                            {item.label}
                        </Link>
                    );
                })}
            </nav>

            {/* Spacer for bottom nav on mobile */}
            <div className="h-14 lg:hidden" />

            <Toaster />
        </div>
    );
}
