import { createInertiaApp } from '@inertiajs/react';
import axios from 'axios';
import ErrorBoundary from '@/components/ErrorBoundary';
import { initializeTheme } from '@/hooks/use-appearance';
import { DEFAULT_APP_NAME, formatPageTitle } from '@/lib/page-title';

// Ensure cookies are sent with every request (session, XSRF-TOKEN)
axios.defaults.withCredentials = true;
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

const appName = import.meta.env.VITE_APP_NAME || DEFAULT_APP_NAME;

createInertiaApp({
    title: (title) => formatPageTitle(title ?? '', appName),
    strictMode: true,
    withApp(app) {
        return (
            <ErrorBoundary>
                {app}
            </ErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
