import { describe, expect, it } from 'vitest';
import { DEFAULT_APP_NAME, formatPageTitle } from '@/lib/page-title';

describe('formatPageTitle', () => {
    it('adds the brand once to a regular title', () => {
        expect(formatPageTitle('Календарь', 'ИРСИ')).toBe('Календарь — ИРСИ');
    });

    it('returns the brand alone for an empty title', () => {
        expect(formatPageTitle('', 'ИРСИ')).toBe('ИРСИ');
    });

    it('keeps a custom application name', () => {
        expect(formatPageTitle('Подтверждение входа', 'Мой Салон')).toBe('Подтверждение входа — Мой Салон');
        expect(formatPageTitle('', 'Мой Салон')).toBe('Мой Салон');
    });

    it('falls back to the default brand', () => {
        expect(DEFAULT_APP_NAME).toBe('ИРСИ');
        expect(formatPageTitle('', DEFAULT_APP_NAME)).toBe('ИРСИ');
    });
});
