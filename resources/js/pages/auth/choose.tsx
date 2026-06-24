import { Head, router } from '@inertiajs/react';
import { MessageCircle, Smartphone, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

export default function Choose() {
    return (
        <>
            <Head title="Вход — Вовремя" />

            <div className="flex min-h-screen items-center justify-center bg-[#FAF8F5] px-5 dark:bg-[#121110]">
                <div className="w-full max-w-md">
                    <div className="text-center">
                        <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                            вовремя
                        </span>
                        <h1 className="mt-8 text-2xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                            Выберите способ быстрой авторизации
                        </h1>
                        <p className="mt-2 text-sm text-stone-400 dark:text-stone-500">
                            Вход за секунду — без пароля и SMS
                        </p>
                    </div>

                    <div className="mt-8 space-y-3">
                        <Button
                            size="lg"
                            className="group h-14 w-full rounded-2xl bg-[#2AABEE] text-base font-semibold text-white shadow-lg shadow-[#2AABEE]/20 transition-all hover:scale-[1.02] hover:shadow-xl dark:bg-[#2AABEE] dark:text-white"
                            onClick={() => router.get('/auth/provider/telegram')}
                        >
                            <MessageCircle className="size-5" />
                            Войти через Telegram
                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                        </Button>

                        <Button
                            size="lg"
                            variant="outline"
                            className="group h-14 w-full rounded-2xl border-2 border-stone-200 bg-white text-base font-semibold text-stone-900 transition-all hover:scale-[1.02] hover:bg-stone-50 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-50 dark:hover:bg-stone-800"
                            onClick={() => router.get('/auth/provider/max')}
                        >
                            <Smartphone className="size-5" />
                            Войти через Max
                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                        </Button>
                    </div>

                    <p className="mt-6 text-center text-xs text-stone-400 dark:text-stone-500">
                        Нажимая кнопку, вы соглашаетесь с условиями сервиса
                    </p>
                </div>
            </div>
        </>
    );
}
