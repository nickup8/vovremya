import { useState } from 'react';
import { Menu, Sun, Moon, Monitor } from 'lucide-react';
import type { ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import Sidebar from '@/components/admin/Sidebar';
import { getInitials } from '@/lib/utils';
import { useAppearance } from '@/hooks/use-appearance';

interface AdminLayoutProps {
    children: ReactNode;
    title: string;
    auth?: { user?: { name?: string; tariff_name?: string; [key: string]: unknown } };
    headerActions?: ReactNode;
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
            title={`Тема: ${appearance === 'light' ? 'Светлая' : appearance === 'dark' ? 'Тёмная' : 'Системная'}`}
            className="rounded-md p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
        >
            {appearance === 'light' && <Sun className="size-5" />}
            {appearance === 'dark' && <Moon className="size-5" />}
            {appearance === 'system' && <Monitor className="size-5" />}
        </button>
    );
}

export default function AdminLayout({ children, title, auth, headerActions }: AdminLayoutProps) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    const userName = auth?.user?.name || 'Мастер';
    const tariffName = auth?.user?.tariff_name || 'Free';
    const initials = getInitials(userName);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-zinc-900 dark:text-zinc-50">
            {/* Sidebar — fixed on desktop */}
            <Sidebar mobileOpen={mobileMenuOpen} onMobileClose={() => setMobileMenuOpen(false)} />

            {/* Content area — offset by sidebar width on desktop */}
            <div className="flex min-h-screen flex-col lg:ml-64">
                <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-xs md:px-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="flex items-center gap-4">
                        <button
                            onClick={() => setMobileMenuOpen(true)}
                            className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800 lg:hidden"
                        >
                            <Menu className="size-5 text-slate-700 dark:text-zinc-300" />
                        </button>
                        <h1 className="text-lg font-semibold text-slate-900 dark:text-zinc-100 md:text-xl">
                            {title}
                        </h1>
                    </div>
                    <div className="flex items-center gap-2">
                        {headerActions}
                        <ThemeToggle />
                        <div className="hidden text-right sm:block">
                            <p className="text-sm font-medium text-slate-700 dark:text-zinc-300">{userName}</p>
                            <p className="text-xs text-slate-400 dark:text-zinc-500">Тариф: {tariffName}</p>
                        </div>
                        <div className="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-bold text-white">
                            {initials}
                        </div>
                    </div>
                </header>

                <main className="flex-1 overflow-y-auto p-4 md:p-6">
                    {children}
                </main>
            </div>
            <Toaster />
        </div>
    );
}
