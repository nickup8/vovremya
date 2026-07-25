import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
    fallback?: ReactNode;
}

interface State {
    hasError: boolean;
}

export default class ErrorBoundary extends Component<Props, State> {
    constructor(props: Props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
        console.error('ErrorBoundary caught an error:', error, errorInfo);
    }

    render(): ReactNode {
        if (this.state.hasError) {
            if (this.props.fallback) {
                return this.props.fallback;
            }

            return (
                <div className="flex min-h-screen items-center justify-center bg-slate-50 dark:bg-zinc-900">
                    <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <svg className="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                        </div>
                        <h2 className="mb-2 text-lg font-semibold text-slate-900 dark:text-zinc-100">
                            Что-то пошло не так
                        </h2>
                        <p className="mb-6 text-sm text-slate-500 dark:text-zinc-400">
                            Произошла непредвиденная ошибка. Попробуйте обновить страницу.
                        </p>
                        <button
                            onClick={() => window.location.reload()}
                            className="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
                        >
                            Обновить страницу
                        </button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
