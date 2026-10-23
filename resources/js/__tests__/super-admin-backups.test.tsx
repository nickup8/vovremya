import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import Backups from '@/pages/SuperAdmin/Backups';

const mockUsePage = vi.fn();
const { routerPost } = vi.hoisted(() => ({
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: {
        get: vi.fn(),
        post: routerPost,
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
}));

function makeBackup(overrides: Record<string, unknown> = {}) {
    return {
        id: 'b-1',
        trigger: 'manual',
        status: 'success',
        requested_at: '2026-10-26T00:30:00Z',
        started_at: '2026-10-26T00:30:05Z',
        finished_at: '2026-10-26T00:31:00Z',
        size_bytes: 315392,
        sha256: 'a'.repeat(64),
        error_message: null,
        prune_error_message: null,
        requested_by: { id: 'u-1', name: 'Иван ROOT' },
        ...overrides,
    };
}

function makeProps(overrides: Record<string, unknown> = {}) {
    return {
        backups: [makeBackup()],
        last_success_at: '2026-10-26T00:31:00Z',
        stale_warning: false,
        warning_after_hours: 48,
        active: false,
        schedule: { enabled: false, time: '03:30', timezone: 'Europe/Moscow' },
        display_timezone: 'Europe/Moscow',
        flash: {},
        platformAdmin: { isRoot: true, permissions: [] },
        ...overrides,
    };
}

describe('SuperAdmin/Backups', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue({ props: makeProps() });
        routerPost.mockReset();
    });

    it('renders list with trigger, status, size and MSK timestamps', () => {
        render(<Backups />);

        expect(screen.getByRole('heading', { name: 'Резервные копии' })).toBeInTheDocument();
        expect(screen.getByTestId('backups-table')).toBeInTheDocument();
        expect(screen.getByText('Вручную')).toBeInTheDocument();
        expect(screen.getByText('Успешно')).toBeInTheDocument();
        expect(screen.getByText(/308\s?КБ/u)).toBeInTheDocument();
        // 00:31 UTC = 03:31 МСК
        expect(screen.getAllByText(/26\.10\.2026.*03:31.*МСК/u).length).toBeGreaterThan(0);
        expect(screen.getByText('Иван ROOT')).toBeInTheDocument();
    });

    it('shows stale warning when there is no fresh successful backup', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({ stale_warning: true, last_success_at: null }),
        });

        render(<Backups />);

        expect(screen.getByTestId('stale-warning')).toBeInTheDocument();
        expect(screen.getByTestId('last-success')).toHaveTextContent('—');
    });

    it('queues backup on button click and prevents double submission', () => {
        render(<Backups />);

        const button = screen.getByTestId('create-backup');
        expect(button).toBeEnabled();

        fireEvent.click(button);
        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        fireEvent.click(button);
        expect(routerPost).toHaveBeenCalledTimes(1);
    });

    it('disables button while a backup is already active', () => {
        mockUsePage.mockReturnValue({ props: makeProps({ active: true }) });

        render(<Backups />);

        const button = screen.getByTestId('create-backup');
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Копия выполняется…');

        fireEvent.click(button);
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('shows failed state with safe error message', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({
                backups: [makeBackup({ status: 'failed', error_message: 'pg_dump завершился с кодом 1.', sha256: null, size_bytes: null })],
            }),
        });

        render(<Backups />);

        expect(screen.getByText('Ошибка')).toBeInTheDocument();
        expect(screen.getByText('pg_dump завершился с кодом 1.')).toBeInTheDocument();
    });

    it('shows running and queued states', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({
                backups: [
                    makeBackup({ id: 'b-2', status: 'running', finished_at: null }),
                    makeBackup({ id: 'b-3', status: 'queued', finished_at: null, requested_by: null }),
                ],
            }),
        });

        render(<Backups />);

        expect(screen.getByText('Выполняется')).toBeInTheDocument();
        expect(screen.getByText('Ожидает')).toBeInTheDocument();
    });

    it('shows prune error separately without failing the backup status', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({
                backups: [makeBackup({ prune_error_message: 'Очистка старых копий: S3 delete failed' })],
            }),
        });

        render(<Backups />);

        expect(screen.getByText('Успешно')).toBeInTheDocument();
        expect(screen.getByText(/Очистка старых копий/u)).toBeInTheDocument();
    });

    it('shows schedule status in header', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({ schedule: { enabled: true, time: '03:30', timezone: 'Europe/Moscow' } }),
        });

        render(<Backups />);

        expect(screen.getByText(/ежедневно в 03:30/u)).toBeInTheDocument();
    });

    it('shows flash messages', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({ flash: { success: 'Задание поставлено в очередь.', error: 'Копия уже выполняется.' } }),
        });

        render(<Backups />);

        expect(screen.getByText('Задание поставлено в очередь.')).toBeInTheDocument();
        expect(screen.getByText('Копия уже выполняется.')).toBeInTheDocument();
    });
});
