import { Head } from '@inertiajs/react';
import { XCircle } from 'lucide-react';

export default function InvalidInvitePage() {
    return (
        <>
            <Head title="Ссылка недействительна — Вовремя" />

            <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 dark:bg-zinc-950">
                <div className="w-full max-w-md text-center">
                    <div className="rounded-2xl border border-slate-200/60 bg-white/50 p-8 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/50">
                        <div className="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-red-50 dark:bg-red-950/30">
                            <XCircle className="size-8 text-red-500 dark:text-red-400" />
                        </div>

                        <h1 className="mb-3 text-xl font-bold text-slate-900 dark:text-zinc-100">
                            Ссылка недействительна
                        </h1>

                        <p className="mb-6 text-sm leading-relaxed text-slate-500 dark:text-zinc-400">
                            Возможно, срок действия приглашения истёк или оно уже было использовано.
                            Попросите администратора отправить новую ссылку.
                        </p>

                        <a
                            href="/"
                            className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-slate-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200"
                        >
                            На главную
                        </a>
                    </div>
                </div>
            </div>
        </>
    );
}
