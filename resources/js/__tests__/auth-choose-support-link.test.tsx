import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import Choose from '@/pages/auth/choose';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...rest }: { children: React.ReactNode; href: string }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: {} }),
}));

vi.mock('@/layouts/PublicLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn().mockResolvedValue({ data: { token: 'test_token_123' } }),
    },
}));

describe('auth/choose — ссылка в поддержку', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('гость видит ссылку «Написать в поддержку» с точным href', async () => {
        render(<Choose maxBotName="irsi_bot" />);

        const link = screen.getByRole('link', { name: 'Написать в поддержку' });
        expect(link).toHaveAttribute('href', 'https://vk.me/irsi_app');

        await waitFor(() => {
            expect(screen.getByRole('link', { name: /Продолжить через MAX/ })).toBeInTheDocument();
        });
    });

    it('ссылка не скрыта на мобильном (нет hidden/ responsive-скрытия)', async () => {
        render(<Choose maxBotName="irsi_bot" />);

        await waitFor(() => {
            expect(screen.getByRole('link', { name: /Продолжить через MAX/ })).toBeInTheDocument();
        });

        const link = screen.getByRole('link', { name: 'Написать в поддержку' });
        const className = link.className + (link.parentElement?.className ?? '');
        expect(className).not.toMatch(/\bhidden\b/);
        expect(className).not.toMatch(/max-md:hidden/);
        expect(className).not.toMatch(/max-lg:hidden/);
    });

    it('варианты входа остаются доступными', async () => {
        render(<Choose maxBotName="irsi_bot" />);

        expect(screen.getByRole('button', { name: /Продолжить через VK/ })).toBeEnabled();

        await waitFor(() => {
            const maxLink = screen.getByRole('link', { name: /Продолжить через MAX/ });
            expect(maxLink).toHaveAttribute('href', 'https://max.ru/irsi_bot?start=test_token_123');
            expect(maxLink).not.toHaveAttribute('aria-disabled', 'true');
        });
    });
});
