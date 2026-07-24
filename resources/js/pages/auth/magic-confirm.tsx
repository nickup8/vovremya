import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { LogIn, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';

MagicConfirm.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    token: string;
}

export default function MagicConfirm({ token }: PageProps) {
    const [loading, setLoading] = useState(false);

    function submit() {
        setLoading(true);
        router.post('/auth/magic', { token });
    }

    return (
        <>
            <Head title="Подтверждение входа — Вовремя" />

            <div className="flex min-h-screen items-center justify-center bg-[#FAF8F5] px-5 dark:bg-[#121110]">
                <div className="w-full max-w-md text-center">
                    <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                        вовремя
                    </span>

                    <h1 className="mt-8 text-2xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                        Вход в кабинет
                    </h1>

                    <p className="mt-3 text-sm text-stone-500 dark:text-stone-400">
                        Нажмите кнопку ниже, чтобы войти в свой аккаунт.
                    </p>

                    <div className="mt-8">
                        <Button
                            size="lg"
                            onClick={submit}
                            disabled={loading}
                            className="group h-14 w-full rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 text-base font-semibold text-white shadow-lg shadow-blue-500/25 transition-all hover:from-blue-700 hover:to-indigo-700 hover:shadow-xl disabled:opacity-50"
                        >
                            {loading ? (
                                <>
                                    <Loader2 className="size-5 animate-spin" />
                                    Вход...
                                </>
                            ) : (
                                <>
                                    <LogIn className="size-5" />
                                    Войти в кабинет
                                </>
                            )}
                        </Button>
                    </div>

                    <p className="mt-8 text-xs text-stone-400 dark:text-stone-500">
                        Ссылка действует 15 минут. Если не работает — запросите новый вход через бота.
                    </p>
                </div>
            </div>
        </>
    );
}
