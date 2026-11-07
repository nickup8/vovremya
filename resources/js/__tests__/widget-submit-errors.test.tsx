import { describe, it, expect, vi, beforeEach, beforeAll, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';
import React from 'react';

const mockUsePage = vi.fn();
const mockRouterGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => React.createElement('title', null, title),
    Link: ({ children, ...p }: any) => React.createElement('a', p, children),
    router: { get: (...args: unknown[]) => mockRouterGet(...args) },
    usePage: () => mockUsePage(),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...p }: any) => React.createElement('button', p, children),
}));

vi.mock('@/lib/utils', () => ({
    getInitials: () => 'TM',
}));

function makePageProps(overrides: Record<string, unknown> = {}) {
    return {
        props: {
            master: {
                name: 'Тест Мастер',
                specialty: 'Парикмахер',
                address: null,
                avatar_url: null,
                master_slug: 'test-master',
            },
            services: [{ id: 'svc-1', title: 'Стрижка', duration_minutes: 60, price: 1000 }],
            availableSlots: ['10:00', '11:00'],
            selectedDate: '2025-07-01',
            selectedServiceId: null,
            maxBotName: 'test_bot',
            ...overrides,
        },
    };
}

let Widget: typeof import('@/pages/booking/widget').default;

beforeAll(async () => {
    const mod = await import('@/pages/booking/widget');
    Widget = mod.default;
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

async function navigateToProviderStep() {
    mockUsePage.mockReturnValue(
        makePageProps({
            selectedServiceId: 'svc-1',
            preselectedServiceId: 'svc-1',
        }),
    );

    mockRouterGet.mockImplementation(
        (_url: string, _data: unknown, opts: Record<string, Function>) => {
            opts.onSuccess?.({ props: { availableSlots: ['10:00', '11:00'] } });
            opts.onFinish?.();
        },
    );

    render(React.createElement(Widget));

    fireEvent.click(screen.getByText('Выбрать время'));

    await waitFor(() => {
        expect(screen.getByText('10:00')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('10:00'));
    fireEvent.click(screen.getByText('Далее'));
    await waitFor(() => {
        expect(screen.getByText('Подтвердите запись')).toBeInTheDocument();
    });
}

function mockFetchOnce(response: {
    ok: boolean;
    status: number;
    json?: () => Promise<unknown>;
}) {
    const json =
        response.json ??
        (() => Promise.reject(new SyntaxError('Unexpected token < in JSON')));
    const fetchMock = vi.fn().mockResolvedValue({
        ok: response.ok,
        status: response.status,
        json,
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

describe('Booking widget — submit errors by field', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        mockUsePage.mockReset();
        mockRouterGet.mockReset();
    });

    it('422 on time shows message under selected time in summary', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 422,
            json: () =>
                Promise.resolve({
                    message: 'The given data was invalid.',
                    errors: { time: ['Этот слот недоступен.'] },
                }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(screen.getByText('Этот слот недоступен.')).toBeInTheDocument();
        });
        // Не уходит в general-блок рядом с кнопками как единственный слот ошибки
        expect(screen.queryByText('The given data was invalid.')).not.toBeInTheDocument();
        expect(screen.getByText('Подтвердите запись')).toBeInTheDocument();
    });

    it('422 on service_id shows message under service title', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 422,
            json: () =>
                Promise.resolve({
                    errors: { service_id: ['Эта услуга больше недоступна для записи.'] },
                }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(
                screen.getByText('Эта услуга больше недоступна для записи.'),
            ).toBeInTheDocument();
        });
        expect(screen.getByText('Стрижка')).toBeInTheDocument();
    });

    it('422 on unknown field shows general message near booking buttons', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 422,
            json: () => Promise.resolve({ errors: { provider: ['Недопустимый способ записи.'] } }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(screen.getByText('Недопустимый способ записи.')).toBeInTheDocument();
        });
        // Рядом с кнопками провайдеров
        expect(screen.getByText('VK')).toBeInTheDocument();
        expect(screen.getByText('MAX')).toBeInTheDocument();
    });

    it('keeps separate limit handling with amber banner', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 422,
            json: () =>
                Promise.resolve({
                    errors: { limit: ['Достигнут дневной лимит записей.'] },
                }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(screen.getByText('Достигнут дневной лимит записей.')).toBeInTheDocument();
        });
        // limit не подменяет time/general
        expect(screen.queryByText('Этот слот недоступен.')).not.toBeInTheDocument();
    });

    it('500 with internal message shows Russian fallback, not internals', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 500,
            json: () =>
                Promise.resolve({
                    message: 'SQLSTATE[23505]: duplicate key value violates unique constraint',
                }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(
                screen.getByText('Сервер временно недоступен. Попробуйте позже.'),
            ).toBeInTheDocument();
        });
        expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
        expect(screen.queryByText(/duplicate key/)).not.toBeInTheDocument();
    });

    it('non-JSON body shows Russian fallback', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 500,
            // json() бросает — как при HTML-странице ошибки
            json: () => Promise.reject(new SyntaxError('Unexpected token <')),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(
                screen.getByText('Сервер временно недоступен. Попробуйте позже.'),
            ).toBeInTheDocument();
        });
        expect(screen.queryByText(/<html/i)).not.toBeInTheDocument();
    });

    it('422 with message-only body shows that message as general', async () => {
        await navigateToProviderStep();

        mockFetchOnce({
            ok: false,
            status: 422,
            json: () => Promise.resolve({ message: 'Слот только что заняли. Выберите другое время.' }),
        });

        fireEvent.click(screen.getByText('VK'));

        await waitFor(() => {
            expect(
                screen.getByText('Слот только что заняли. Выберите другое время.'),
            ).toBeInTheDocument();
        });
    });
});
