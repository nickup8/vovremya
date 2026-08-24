import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, waitFor, fireEvent } from '@testing-library/react';
import React from 'react';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: any) => React.createElement(React.Fragment, null, children),
    Link: ({ children, ...p }: any) => React.createElement('a', p, children),
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({
        props: {
            master: { name: 'Тест', specialty: null, address: null, avatar_url: null, master_slug: 'test-master' },
            services: [{ id: '1', title: 'Стрижка', price: 1000, duration_minutes: 60 }],
            selectedDate: '2026-08-15',
            selectedServiceId: '1',
            maxBotName: null,
        },
    }),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...p }: any) => React.createElement('button', p, children),
}));

vi.mock('lucide-react', () => {
    const Icon = () => null;
    const Loader2 = ({ className }: { className?: string }) =>
        React.createElement('div', { className });
    return {
        ArrowRight: Icon, ArrowLeft: Icon, Clock: Icon,
        CheckCircle2: Icon, MessageCircle: Icon,
        ChevronLeft: Icon, ChevronRight: Icon, MapPin: Icon, Loader2,
    };
});

vi.mock('@/layouts/PublicLayout', () => ({
    default: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@/lib/utils', () => ({
    getInitials: () => 'Т',
}));

interface Deferred<T> {
    promise: Promise<T>;
    resolve: (value: T) => void;
    reject: (reason?: unknown) => void;
}

function createDeferred<T>(): Deferred<T> {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

function createControlledFetch() {
    const requests: Array<{
        url: string;
        signal: AbortSignal;
        deferred: Deferred<Response>;
    }> = [];

    const mockFetch = vi.fn((url: string, init?: { signal?: AbortSignal }) => {
        const deferred = createDeferred<Response>();
        const signal = init?.signal ?? new AbortController().signal;
        requests.push({ url, signal, deferred });

        if (signal) {
            signal.addEventListener('abort', () => {
                deferred.reject(new DOMException('The operation was aborted.', 'AbortError'));
            });
        }

        return deferred.promise;
    });

    return {
        mockFetch,
        getRequests: () => requests,
        resolveRequest: (index: number, dates: string[]) => {
            requests[index].deferred.resolve({
                json: () => Promise.resolve({ dates }),
            } as Response);
        },
    };
}

function clickNextMonth(container: HTMLElement) {
    const allButtons = container.querySelectorAll('button');
    const nextMonthBtn = allButtons[2];
    fireEvent.click(nextMonthBtn);
}

describe('StepDate — race condition fix', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        vi.restoreAllMocks();
    });

    it('stale month response does not overwrite new month data', async () => {
        const { mockFetch, getRequests, resolveRequest } = createControlledFetch();
        globalThis.fetch = mockFetch;

        const { default: Widget } = await import('@/pages/booking/widget');
        const { container } = render(React.createElement(Widget));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        expect(getRequests()[0].url).toContain('month=');

        act(() => {
            clickNextMonth(container);
        });

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        expect(getRequests()[0].signal.aborted).toBe(true);
        expect(getRequests()[1].signal.aborted).toBe(false);

        await act(async () => {
            resolveRequest(1, ['2026-04-02', '2026-04-07']);
        });

        await waitFor(() => {
            expect(screen.queryAllByText('2').length).toBeGreaterThan(0);
        });
    });

    it('previous request is aborted when month changes', async () => {
        const { mockFetch, getRequests, resolveRequest } = createControlledFetch();
        globalThis.fetch = mockFetch;

        const { default: Widget } = await import('@/pages/booking/widget');
        const { container } = render(React.createElement(Widget));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        expect(getRequests()[0].signal.aborted).toBe(false);

        act(() => {
            clickNextMonth(container);
        });

        await waitFor(() => {
            expect(getRequests()[0].signal.aborted).toBe(true);
        });

        await waitFor(() => {
            expect(getRequests()).toHaveLength(2);
            expect(getRequests()[1].signal.aborted).toBe(false);
        });

        await act(async () => {
            resolveRequest(1, ['2026-04-01']);
        });
    });

    it('stale response does not disable loading for new request', async () => {
        const { mockFetch, getRequests, resolveRequest } = createControlledFetch();
        globalThis.fetch = mockFetch;

        const { default: Widget } = await import('@/pages/booking/widget');
        const { container } = render(React.createElement(Widget));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        act(() => {
            clickNextMonth(container);
        });

        await waitFor(() => {
            expect(getRequests()).toHaveLength(2);
        });

        const loader = container.querySelector('.animate-spin');
        expect(loader).toBeTruthy();

        await act(async () => {
            resolveRequest(1, ['2026-04-01']);
        });

        await waitFor(() => {
            const loaderAfter = container.querySelector('.animate-spin');
            expect(loaderAfter).toBeNull();
        });
    });

    it('unmount aborts pending fetch', async () => {
        const { mockFetch, getRequests } = createControlledFetch();
        globalThis.fetch = mockFetch;

        const { default: Widget } = await import('@/pages/booking/widget');
        const { unmount } = render(React.createElement(Widget));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        expect(getRequests()[0].signal.aborted).toBe(false);

        unmount();

        expect(getRequests()[0].signal.aborted).toBe(true);
    });
});
