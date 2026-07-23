import { Head } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import PublicLayout from '@/layouts/PublicLayout';

Unavailable.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    workspaceName?: string;
}

export default function Unavailable({ workspaceName }: PageProps) {
    return (
        <>
            <Head title="Запись недоступна — Вовремя" />

            <div className="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center bg-[#FAF8F5] px-5 dark:bg-[#121110]">
                <div className="flex size-16 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                    <Ban className="size-8 text-stone-400 dark:text-stone-500" />
                </div>

                <h1 className="mt-6 text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                    Запись недоступна
                </h1>

                <p className="mt-2 max-w-xs text-center text-sm leading-relaxed text-stone-500 dark:text-stone-400">
                    {workspaceName
                        ? `Мастер работает в составе студии «${workspaceName}». Пожалуйста, свяжитесь со студией для записи.`
                        : 'Эта страница записи недоступна.'}
                </p>
            </div>
        </>
    );
}
