import { Head } from '@inertiajs/react';
import { Crown, Send, MessageCircle } from 'lucide-react';

interface InviteShowProps {
    token: string;
    workspaceName: string;
    tgBot: string;
    maxBot: string | null;
}

export default function InviteShowPage({ token, workspaceName, tgBot, maxBot }: InviteShowProps) {
    const tgLink = `https://t.me/${tgBot}?start=inv_${token}`;
    const maxLink = maxBot ? `https://max.ru/${maxBot}?start=inv_${token}` : null;

    return (
        <>
            <Head title="Приглашение в команду — Вовремя" />

            <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 dark:bg-zinc-950">
                <div className="w-full max-w-md">
                    <div className="rounded-2xl border border-slate-200/60 bg-white/50 p-8 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/50">
                        <div className="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-orange-500">
                            <Crown className="size-8 text-white" />
                        </div>

                        <h1 className="mb-3 text-center text-xl font-bold text-slate-900 dark:text-zinc-100">
                            Приглашение в команду
                        </h1>

                        <p className="mb-8 text-center text-sm leading-relaxed text-slate-500 dark:text-zinc-400">
                            Вас пригласили стать мастером в{' '}
                            <span className="font-semibold text-slate-700 dark:text-zinc-200">
                                {workspaceName}
                            </span>.
                            <br />
                            Выберите платформу для авторизации и настройки графика:
                        </p>

                        <div className="space-y-3">
                            <a
                                href={tgLink}
                                className="flex items-center gap-4 rounded-xl border border-blue-200 bg-blue-50/80 p-4 transition-all hover:border-blue-300 hover:bg-blue-100/80 hover:shadow-md hover:shadow-blue-500/10 dark:border-blue-800/50 dark:bg-blue-950/30 dark:hover:border-blue-700/50 dark:hover:bg-blue-950/50"
                            >
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-500 text-white">
                                    <Send className="size-5" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                        Telegram
                                    </p>
                                    <p className="text-xs text-blue-600/70 dark:text-blue-400/60">
                                        Откроется бот для авторизации
                                    </p>
                                </div>
                            </a>

                            {maxLink && (
                                <a
                                    href={maxLink}
                                    className="flex items-center gap-4 rounded-xl border border-purple-200 bg-purple-50/80 p-4 transition-all hover:border-purple-300 hover:bg-purple-100/80 hover:shadow-md hover:shadow-purple-500/10 dark:border-purple-800/50 dark:bg-purple-950/30 dark:hover:border-purple-700/50 dark:hover:bg-purple-950/50"
                                >
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-purple-500 text-white">
                                        <MessageCircle className="size-5" />
                                    </div>
                                    <div>
                                        <p className="text-sm font-semibold text-purple-900 dark:text-purple-100">
                                            МАКС
                                        </p>
                                        <p className="text-xs text-purple-600/70 dark:text-purple-400/60">
                                            Откроется бот для авторизации
                                        </p>
                                    </div>
                                </a>
                            )}
                        </div>
                    </div>

                    <p className="mt-6 text-center text-[11px] text-slate-400 dark:text-zinc-600">
                        Ссылка действует 24 часа
                    </p>
                </div>
            </div>
        </>
    );
}
