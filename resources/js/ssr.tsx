import { createInertiaApp } from '@inertiajs/react';
import type { ReactElement } from 'react';
import ErrorBoundary from '@/components/ErrorBoundary';
import { DEFAULT_APP_NAME, formatPageTitle } from '@/lib/page-title';

/* SSR entry — только гостевой лендинг (welcome-v2) и его зависимости.
   Кабинеты, /book/* и мини-аппы в серверный рендер не попадают:
   resolve ниже отклоняет любые другие компоненты, а Laravel не отдаёт
   им SSR-ответ (см. Inertia::disableSsr в AppServiceProvider). */

const appName = import.meta.env.VITE_APP_NAME || DEFAULT_APP_NAME;

createInertiaApp({
    title: (title: string) => formatPageTitle(title, appName),
    strictMode: true,
    resolve: async (name: string) => {
        if (name !== 'welcome-v2') {
            throw new Error(`SSR entry does not support page "${name}"`);
        }

        return (await import('./pages/welcome-v2')).default;
    },
    withApp: (app: ReactElement) => <ErrorBoundary>{app}</ErrorBoundary>,
});
