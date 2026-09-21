import { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, ChevronRight } from 'lucide-react';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    maxBotName: string | null;
    [key: string]: unknown;
}

export default function Choose({ maxBotName }: PageProps) {
    const [token, setToken] = useState<string | null>(null);
    const [tokenLoading, setTokenLoading] = useState(true);
    const [vkLoading, setVkLoading] = useState(false);
    const vkFormRef = useRef<HTMLFormElement>(null);
    const { flash } = usePage().props as { flash?: { error?: string | null } };

    const flashError = flash?.error ?? null;

    // ─── CSRF token для VK form ───
    useEffect(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        const form = vkFormRef.current;
        if (meta && form && !form.querySelector('input[name="_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = meta.getAttribute('content') || '';
            form.appendChild(input);
        }
    }, []);

    // ─── Получаем login_token для MAX deep link ───
    useEffect(() => {
        let cancelled = false;

        axios.post('/auth/telegram/token')
            .then(({ data }) => {
                if (cancelled) return;
                if (data.token) {
                    setToken(data.token);
                }
                setTokenLoading(false);
            })
            .catch((err) => {
                if (cancelled) return;
                console.error('Token fetch error:', err);
                setTokenLoading(false);
            });

        return () => { cancelled = true; };
    }, []);

    const maxBotLink = token && maxBotName
        ? `https://max.ru/${maxBotName}?start=${token}`
        : '#';

    const isMaxDisabled = tokenLoading || !token || !maxBotName;

    return (
        <>
            <Head title="Вход — ИРСИ" />

            <main className="grid min-h-dvh place-items-center bg-[var(--color-warm)] px-5 py-8 max-md:items-start max-md:py-[calc(64px+env(safe-area-inset-top))] dark:bg-[#121110]">
                <section className="grid w-full max-w-[400px] gap-8 max-md:w-full max-md:gap-7">

                    {/* Logo */}
                    <div className="flex justify-center max-md:justify-start">
                        <img src="/images/logo.svg" alt="ИРСИ" className="w-[118px] max-md:w-[108px]" />
                    </div>

                    {/* Header */}
                    <header className="text-center max-md:text-left">
                        <h1 className="text-[36px] font-[650] leading-[44px] tracking-[-0.025em] text-[var(--color-ink,#181818)] dark:text-stone-50 max-md:text-[32px] max-md:leading-[40px] max-md:tracking-[-0.02em]">
                            Вход в личный кабинет
                        </h1>
                        <p className="mt-2 text-[15px] font-normal leading-[22px] text-[var(--color-graphite,#62615F)] dark:text-stone-400">
                            Выберите мессенджер
                        </p>
                    </header>

                    {/* Flash error */}
                    {flashError && (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-medium text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                            {flashError}
                        </div>
                    )}

                    {/* Auth buttons — 360px group centered in 400px column */}
                    <div className="mx-auto grid w-full max-w-[360px] gap-2 max-md:max-w-full">

                        {/* VK */}
                        <form ref={vkFormRef} method="POST" action="/auth/vk/start" className="hidden" />
                        <button
                            type="button"
                            onClick={() => {
                                setVkLoading(true);
                                vkFormRef.current?.submit();
                            }}
                            disabled={vkLoading}
                            className="group flex h-14 w-full items-center gap-4 rounded-xl bg-[#0077FF] px-4 text-[14px] font-semibold text-white shadow-md shadow-[#0077FF]/20 transition-all duration-150 hover:-translate-y-px hover:shadow-lg active:translate-y-0 disabled:cursor-default disabled:opacity-50 disabled:hover:translate-y-0"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-white">
                                <svg className="size-[26px]" viewBox="0 0 24 24" fill="#0077FF">
                                    <path d="M12.785 16.241s.288-.032.436-.194c.136-.148.132-.427.132-.427s-.02-1.304.587-1.496c.598-.189 1.368 1.26 2.184 1.817.617.42 1.087.328 1.087.328l2.184-.03s1.142-.071.6-.964c-.044-.073-.316-.656-1.624-1.854-1.37-1.256-1.186-1.052.464-3.23.999-1.322 1.398-2.13 1.27-2.484-.121-.335-.87-.248-.87-.248l-2.46.015s-.183-.025-.318.056c-.132.079-.217.263-.217.263s-.389 1.035-.907 1.918c-1.094 1.863-1.532 1.96-1.708 1.846-.415-.266-.312-1.073-.312-1.644 0-1.788.271-2.536-.529-2.73-.266-.064-.462-.107-1.143-.114-.874-.008-1.614.003-2.032.207-.279.136-.495.44-.363.457.163.022.533.1.73.364.254.342.245 1.112.245 1.112s.146 2.125-.34 2.394c-.332.184-.789-.192-1.772-1.915-.502-.884-.88-1.858-.88-1.858s-.073-.178-.204-.274c-.159-.117-.38-.154-.38-.154l-2.34.015s-.35.01-.479.162c-.114.134-.009.41-.009.41s1.84 4.298 3.932 6.465c1.912 1.982 4.086 1.854 4.086 1.854h.988z" />
                                </svg>
                            </span>
                            <span className="flex-1 text-left">
                                {vkLoading ? 'Открываем VK…' : 'Продолжить через VK'}
                            </span>
                            <span className="shrink-0 text-white/70">
                                {vkLoading ? (
                                    <Loader2 className="size-[18px] animate-spin" />
                                ) : (
                                    <ChevronRight className="size-[18px]" strokeWidth={1.8} />
                                )}
                            </span>
                        </button>

                        {/* MAX */}
                        {maxBotName ? (
                            <a
                                href={maxBotLink}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={(e) => {
                                    if (isMaxDisabled) e.preventDefault();
                                }}
                                className="group flex h-14 w-full items-center gap-4 rounded-xl px-4 text-[14px] font-semibold text-white shadow-md shadow-purple-900/20 transition-all duration-150 hover:-translate-y-px hover:shadow-lg active:translate-y-0 disabled:cursor-default disabled:opacity-50 disabled:hover:translate-y-0"
                                style={{
                                    background: 'linear-gradient(105deg, #471AFF 0%, #6E1AFF 54%, #00BFFF 120%)',
                                    opacity: isMaxDisabled ? 0.5 : 1,
                                    pointerEvents: isMaxDisabled ? 'none' : undefined,
                                }}
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-white">
                                    <img src="/images/providers/max.svg" alt="" className="size-[26px]" />
                                </span>
                                <span className="flex-1 text-left">
                                    {tokenLoading ? 'Подготовка…' : 'Продолжить через MAX'}
                                </span>
                                <span className="shrink-0 text-white/70">
                                    {tokenLoading ? (
                                        <Loader2 className="size-[18px] animate-spin" />
                                    ) : (
                                        <ChevronRight className="size-[18px]" strokeWidth={1.8} />
                                    )}
                                </span>
                            </a>
                        ) : null}
                    </div>

                    {/* Supporting text */}
                    <p className="mx-auto w-[340px] max-w-full text-center text-[14px] leading-[20px] text-[var(--color-graphite,#62615F)] dark:text-stone-400 max-md:w-full max-md:text-left">
                        Бот попросит подтвердить вход<br />
                        и поделиться номером телефона.
                    </p>

                    {/* Legal */}
                    <footer className="mx-auto w-[360px] max-w-full text-center max-md:w-full max-md:text-left">
                        <p className="text-[12px] leading-[18px] text-[var(--color-graphite,#62615F)] dark:text-stone-400">
                            Продолжая, вы принимаете{' '}
                            <Link
                                href="/offer"
                                target="_blank"
                                className="font-medium text-[var(--color-ink,#181818)] underline decoration-[#9B9893] decoration-1 underline-offset-[2px] transition-colors duration-150 hover:text-[var(--color-orange,#FF5A1F)] hover:decoration-[var(--color-orange,#FF5A1F)] dark:text-stone-300 dark:hover:text-orange-400"
                            >
                                Публичную оферту
                            </Link>{' '}
                            и&nbsp;<Link
                                href="/privacy"
                                target="_blank"
                                className="font-medium text-[var(--color-ink,#181818)] underline decoration-[#9B9893] decoration-1 underline-offset-[2px] transition-colors duration-150 hover:text-[var(--color-orange,#FF5A1F)] hover:decoration-[var(--color-orange,#FF5A1F)] dark:text-stone-300 dark:hover:text-orange-400"
                            >
                                Политику обработки персональных данных
                            </Link>.
                        </p>
                    </footer>
                </section>
            </main>
        </>
    );
}
