import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { LogIn, Loader2 } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import PublicLayout from '@/layouts/PublicLayout';

MagicConfirm.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    token: string;
}

export default function MagicConfirm({ token }: PageProps) {
    const [loading, setLoading] = useState(false);
    const { resolvedAppearance } = useAppearance();

    const logoSrc = resolvedAppearance === 'dark' ? '/images/logo-white.svg' : '/images/logo.svg';

    function submit() {
        setLoading(true);
        router.post('/auth/magic', { token });
    }

    return (
        <>
            <Head title="Подтверждение входа — ИРСИ" />

            <main className="grid min-h-dvh place-items-center bg-[var(--color-warm)] px-5 py-8 max-md:items-start max-md:py-[calc(64px+env(safe-area-inset-top))] dark:bg-[#121110]">
                <section className="grid w-full max-w-[400px] gap-8 max-md:w-full max-md:gap-7">

                    {/* Logo */}
                    <div className="flex justify-center max-md:justify-start">
                        <img src={logoSrc} alt="ИРСИ" className="w-[118px] max-md:w-[108px]" />
                    </div>

                    {/* Header */}
                    <header className="text-center max-md:text-left">
                        <h1 className="text-[36px] font-[650] leading-[44px] tracking-[-0.025em] text-[var(--color-ink,#181818)] dark:text-stone-50 max-md:text-[32px] max-md:leading-[40px] max-md:tracking-[-0.02em]">
                            Вход в кабинет
                        </h1>
                        <p className="mt-2 text-[15px] font-normal leading-[22px] text-[var(--color-graphite,#62615F)] dark:text-stone-400">
                            Нажмите кнопку, чтобы войти в свой аккаунт ИРСИ
                        </p>
                    </header>

                    {/* Confirm button — 360px group centered in 400px column */}
                    <div className="mx-auto grid w-full max-w-[360px] gap-2 max-md:max-w-full">
                        <button
                            type="button"
                            onClick={submit}
                            disabled={loading}
                            className="group flex h-14 w-full items-center justify-center gap-3 rounded-xl bg-[var(--color-orange)] px-4 text-[14px] font-semibold text-white shadow-md shadow-[var(--color-orange)]/20 transition-all duration-150 hover:-translate-y-px hover:bg-[var(--color-orange-600)] hover:shadow-lg active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-orange)] disabled:cursor-default disabled:opacity-50 disabled:hover:translate-y-0"
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
                        </button>
                    </div>

                    {/* Supporting text */}
                    <p className="mx-auto w-[340px] max-w-full text-center text-[14px] leading-[20px] text-[var(--color-graphite,#62615F)] dark:text-stone-400 max-md:w-full max-md:text-left">
                        Ссылка действует 15 минут с момента получения. Если она устарела, запросите новую в боте, из которого перешли сюда
                    </p>
                </section>
            </main>
        </>
    );
}
