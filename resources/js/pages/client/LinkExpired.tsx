import { Head } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import ClientLayout from '@/layouts/ClientLayout';

export default function LinkExpired() {
    return (
        <>
            <Head title="Ссылка устарела" />
            <div className="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center px-4 text-center">
                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                    <Link2 className="h-8 w-8 text-amber-600 dark:text-amber-400" />
                </div>
                <h1 className="mb-2 text-xl font-semibold text-slate-900 dark:text-zinc-100">
                    Ссылка устарела
                </h1>
                <p className="text-sm text-slate-500 dark:text-zinc-400">
                    Эта ссылка на личный кабинет уже использована или больше недействительна.
                    Откройте кабинет заново через кнопку «👤 Личный кабинет» в боте.
                </p>
            </div>
        </>
    );
}

LinkExpired.layout = (page: React.ReactNode) => <ClientLayout>{page}</ClientLayout>;
