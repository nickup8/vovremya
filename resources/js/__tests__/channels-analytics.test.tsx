import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/components/ui/button', () => ({ Button: ({ children, ...p }: any) => React.createElement('button', p, children) }));
vi.mock('lucide-react', () => {
    const Icon = () => null;

    return { Copy: Icon, Check: Icon, Plus: Icon, Pencil: Icon, Lock: Icon, Link2: Icon };
});
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { ChannelsTab, TopChannelsBlock   } from '@/components/admin/ChannelsAnalytics';
import type {ChannelSource, TrackingLinkItem} from '@/components/admin/ChannelsAnalytics';

const sampleChannels: ChannelSource[] = [
    { key: 'link:1', type: 'tracking', name: 'Instagram', created_count: 10, cancelled_count: 2, completed_count: 6, new_clients_count: 4, returning_clients_count: 2, revenue: 12000, average_check: 2000 },
    { key: 'manual', type: 'manual', name: 'Записано мастером', created_count: 3, cancelled_count: 0, completed_count: 3, new_clients_count: 1, returning_clients_count: 2, revenue: 6000, average_check: 2000 },
    { key: 'direct', type: 'direct', name: 'Без источника / Direct', created_count: 5, cancelled_count: 1, completed_count: 4, new_clients_count: 2, returning_clients_count: 2, revenue: 8000, average_check: 2000 },
];

const sampleLinks: TrackingLinkItem[] = [
    { id: '1', name: 'Instagram', is_active: true, url: 'https://x/r/abc' },
    { id: '2', name: 'VK старое', is_active: false, url: 'https://x/r/def' },
];

describe('ChannelsTab — PROFI', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders tracking links management with active/inactive states', () => {
        render(<ChannelsTab feature={true} channels={sampleChannels} trackingLinks={sampleLinks} />);
        expect(screen.getByText('Tracking-ссылки')).toBeInTheDocument();
        // 'Instagram' присутствует и в управлении, и в таблице аналитики — matches >= 1.
        expect(screen.getAllByText('Instagram').length).toBeGreaterThan(0);
        expect(screen.getByText('VK старое')).toBeInTheDocument();
        expect(screen.getByText('Активна')).toBeInTheDocument();
        expect(screen.getByText('Отключена')).toBeInTheDocument();
    });

    it('renders the analytics table with all required metric columns', () => {
        render(<ChannelsTab feature={true} channels={sampleChannels} trackingLinks={sampleLinks} />);
        ['Источник', 'Записи', 'Отменённые', 'Завершённые', 'Новые', 'Возвратные', 'Выручка', 'Средний чек']
            .forEach((h) => expect(screen.getByText(h)).toBeInTheDocument());
    });

    it('displays Direct and Manual system sources and inactive historical link', () => {
        render(<ChannelsTab feature={true} channels={sampleChannels} trackingLinks={sampleLinks} />);
        expect(screen.getByText('Без источника / Direct')).toBeInTheDocument();
        expect(screen.getByText('Записано мастером')).toBeInTheDocument();
        // inactive historical link shown in management
        expect(screen.getByText('VK старое')).toBeInTheDocument();
    });

    it('has no delete action', () => {
        render(<ChannelsTab feature={true} channels={sampleChannels} trackingLinks={sampleLinks} />);
        expect(screen.queryByText('Удалить')).not.toBeInTheDocument();
        expect(screen.queryByText(/Delete/i)).not.toBeInTheDocument();
    });
});

describe('ChannelsTab — START (locked)', () => {
    beforeEach(() => vi.clearAllMocks());

    it('shows upgrade CTA and no management/metrics', () => {
        render(<ChannelsTab feature={false} channels={null} trackingLinks={null} />);
        expect(screen.getByText('Перейти на Профи')).toBeInTheDocument();
        // no management block, no real data
        expect(screen.queryByText('Tracking-ссылки')).not.toBeInTheDocument();
        expect(screen.queryByText('Источник')).not.toBeInTheDocument();
    });
});

describe('TopChannelsBlock', () => {
    beforeEach(() => vi.clearAllMocks());

    it('PROFI: shows real data and "Смотреть все"', () => {
        const onSeeAll = vi.fn();
        render(<TopChannelsBlock feature={true} channels={sampleChannels} onSeeAll={onSeeAll} />);
        expect(screen.getByText('ТОП-5 каналов записи по выручке')).toBeInTheDocument();
        expect(screen.getByText('Смотреть все')).toBeInTheDocument();
        expect(screen.getByText('Instagram')).toBeInTheDocument();
    });

    it('START: shows locked CTA, no "Смотреть все", no real metrics', () => {
        render(<TopChannelsBlock feature={false} channels={null} onSeeAll={vi.fn()} />);
        expect(screen.getByText('Перейти на Профи')).toBeInTheDocument();
        expect(screen.queryByText('Смотреть все')).not.toBeInTheDocument();
        expect(screen.queryByText('Instagram')).not.toBeInTheDocument();
    });
});
