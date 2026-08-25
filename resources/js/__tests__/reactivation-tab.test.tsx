import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import React from 'react';

/* ── Mocks ── */

const { mockUsePage, mockRouter } = vi.hoisted(() => ({
    mockUsePage: vi.fn(),
    mockRouter: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: any) => React.createElement('title', null, title),
    router: mockRouter,
    usePage: () => mockUsePage(),
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children, title }: any) => React.createElement('div', { 'data-testid': 'admin-layout' }, children),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...p }: any) => React.createElement('button', p, children),
}));

vi.mock('@/components/ui/input', () => ({
    Input: (p: any) => React.createElement('input', p),
}));

vi.mock('@/components/ui/switch', () => ({
    Switch: ({ checked, onCheckedChange, ...p }: any) =>
        React.createElement('input', { type: 'checkbox', checked, onChange: () => onCheckedChange?.(!checked), ...p }),
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: any) => React.createElement('div', null, children),
    AvatarImage: () => null,
    AvatarFallback: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ children }: any) => React.createElement('div', null, children),
    DialogContent: ({ children }: any) => React.createElement('div', null, children),
    DialogHeader: ({ children }: any) => React.createElement('div', null, children),
    DialogTitle: ({ children }: any) => React.createElement('h2', null, children),
    DialogFooter: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@/components/PhoneInput', () => ({
    PhoneInput: (p: any) => React.createElement('input', p),
}));

vi.mock('@/lib/phone', () => ({
    formatPhone: (p: string) => p,
    stripPhoneMask: (p: string) => p,
}));

vi.mock('@/lib/utils', () => ({
    getInitials: (name: string) => name.charAt(0).toUpperCase(),
}));

vi.mock('lucide-react', () => {
    const Icon = (p: any) => React.createElement('span', { 'data-icon': 'true', ...p });
    return {
        Search: Icon, Plus: Icon, Users: Icon, Phone: Icon, Send: Icon, MessageCircle: Icon,
        CalendarPlus: Icon, Pencil: Icon, ShieldCheck: Icon, ShieldOff: Icon, Shield: Icon,
        ChevronLeft: Icon, ChevronRight: Icon, RefreshCw: Icon, Loader2: Icon,
    };
});

/* ── Helpers ── */

function makePageProps(overrides: Record<string, unknown> = {}) {
    return {
        props: {
            clients: {
                data: [],
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
                from: 1,
                to: 0,
            },
            has_reactivation_feature: true,
            auth: { user: { id: '1', name: 'Test', tariff_name: 'pro' } },
            ...overrides,
        },
    };
}

const sampleCandidate = {
    client_id: 'c1',
    client_name: 'Алиса',
    service_catalog_id: 'sc1',
    service_name: 'Массаж',
    source_appointment_id: 'a1',
    last_visit_at: '2026-08-01T10:00:00.000000Z',
    reactivation_days: 21,
    eligible_at: '2026-08-22T10:00:00.000000Z',
    days_overdue: 4,
};

/* ── Tests ── */

import ClientsPage from '@/pages/admin/clients';

describe('Reactivation Tab', () => {
    let fetchSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.clearAllMocks();
        mockUsePage.mockReturnValue(makePageProps());
        fetchSpy = vi.spyOn(globalThis, 'fetch');
    });

    afterEach(() => {
        fetchSpy.mockRestore();
    });

    // ── Tab visibility ──

    it('shows reactivation tab for Pro users', () => {
        mockUsePage.mockReturnValue(makePageProps({ has_reactivation_feature: true }));
        render(<ClientsPage />);
        expect(screen.getByText('На возврат')).toBeInTheDocument();
    });

    it('hides reactivation tab for Start users', () => {
        mockUsePage.mockReturnValue(makePageProps({ has_reactivation_feature: false }));
        render(<ClientsPage />);
        expect(screen.queryByText('На возврат')).not.toBeInTheDocument();
    });

    // ── Lazy loading ──

    it('does not fetch candidates on initial render', () => {
        render(<ClientsPage />);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('fetches candidates when reactivation tab is clicked', async () => {
        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify([sampleCandidate]), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(fetchSpy).toHaveBeenCalledWith('/admin/reactivation/candidates', expect.objectContaining({
            headers: { Accept: 'application/json' },
        }));
    });

    // ── Loading state ──

    it('shows loading indicator while fetching', async () => {
        fetchSpy.mockReturnValueOnce(new Promise(() => {})); // never resolves
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        expect(screen.getByText('Загружаем клиентов…')).toBeInTheDocument();
    });

    // ── Candidate content ──

    it('renders candidate card with correct data', async () => {
        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify([sampleCandidate]), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getByText('Алиса')).toBeInTheDocument();
            expect(screen.getByText('Массаж')).toBeInTheDocument();
            expect(screen.getByText(/21 день/)).toBeInTheDocument();
            expect(screen.getByText(/4 дня назад/)).toBeInTheDocument();
        });
    });

    // ── Today ──

    it('shows "Сегодня пора вернуть" when days_overdue is 0', async () => {
        const todayCandidate = { ...sampleCandidate, days_overdue: 0 };
        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify([todayCandidate]), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getByText('Сегодня пора вернуть')).toBeInTheDocument();
        });
    });

    // ── Multiple services same client ──

    it('renders separate cards for same client with different services', async () => {
        const candidates = [
            sampleCandidate,
            { ...sampleCandidate, service_catalog_id: 'sc2', service_name: 'Маникюр', source_appointment_id: 'a2' },
        ];
        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify(candidates), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getAllByText('Алиса')).toHaveLength(2);
            expect(screen.getByText('Массаж')).toBeInTheDocument();
            expect(screen.getByText('Маникюр')).toBeInTheDocument();
        });
    });

    // ── Empty state ──

    it('shows empty message when no candidates', async () => {
        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify([]), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getByText('Сейчас возвращать никого не нужно')).toBeInTheDocument();
        });
    });

    // ── Error state ──

    it('shows error message on fetch failure', async () => {
        fetchSpy.mockResolvedValueOnce(new Response('', { status: 500 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getByText('Не удалось загрузить список')).toBeInTheDocument();
            expect(screen.getByText('Попробовать снова')).toBeInTheDocument();
        });
    });

    // ── Retry ──

    it('retries fetch on button click', async () => {
        fetchSpy.mockResolvedValueOnce(new Response('', { status: 500 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(screen.getByText('Попробовать снова')).toBeInTheDocument();
        });

        fetchSpy.mockResolvedValueOnce(new Response(JSON.stringify([sampleCandidate]), { status: 200 }));
        fireEvent.click(screen.getByText('Попробовать снова'));

        await waitFor(() => {
            expect(screen.getByText('Алиса')).toBeInTheDocument();
        });
    });

    // ── Abort ──

    it('aborts previous request when switching tabs', async () => {
        const abortSpy = vi.fn();
        const originalAbort = AbortController.prototype.abort;

        vi.spyOn(globalThis, 'fetch').mockImplementation((_url, opts) => {
            if (opts?.signal) {
                opts.signal.addEventListener('abort', abortSpy);
            }
            return new Promise(() => {});
        });

        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));
        fireEvent.click(screen.getByText('Все'));

        expect(abortSpy).toHaveBeenCalled();

        vi.restoreAllMocks();
        fetchSpy = vi.spyOn(globalThis, 'fetch');
    });

    // ── Refetch ──

    it('refetches when re-entering reactivation tab', async () => {
        fetchSpy.mockResolvedValue(new Response(JSON.stringify([]), { status: 200 }));
        render(<ClientsPage />);

        fireEvent.click(screen.getByText('На возврат'));
        expect(fetchSpy).toHaveBeenCalledOnce();

        fireEvent.click(screen.getByText('Все'));
        fireEvent.click(screen.getByText('На возврат'));

        await waitFor(() => {
            expect(fetchSpy).toHaveBeenCalledTimes(2);
        });
    });
});
