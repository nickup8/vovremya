import { describe, it, expect } from 'vitest';
import { buildFreeWindowsTextService, buildFreeWindowsTextAll } from '@/components/admin/freeWindowsTextBuilder';

const TEST_URL = 'https://irsi.test/book/test-master';
const TEST_URL_WITH_SERVICE = 'https://irsi.test/book/test-master?service_id=abc-123';

describe('freeWindowsTextBuilder', () => {
    describe('buildFreeWindowsTextService', () => {
        it('includes absolute URL in output', () => {
            const text = buildFreeWindowsTextService('Маникюр', [
                { date: '2025-09-27', starts: ['12:00', '13:30'] },
            ], TEST_URL);

            expect(text).toContain(`Записаться онлайн: ${TEST_URL}`);
        });

        it('does not contain relative URL', () => {
            const text = buildFreeWindowsTextService('Маникюр', [
                { date: '2025-09-27', starts: ['12:00'] },
            ], TEST_URL);

            expect(text).not.toMatch(/Записаться онлайн: \//);
        });

        it('preserves template format', () => {
            const text = buildFreeWindowsTextService('Маникюр', [
                { date: '2025-09-27', starts: ['12:00', '13:30'] },
            ], TEST_URL);

            expect(text).toMatch(/^Свободное время на Маникюр\n\n27 сентября — 12:00, 13:30\n\nЗаписаться онлайн:/);
        });
    });

    describe('buildFreeWindowsTextAll', () => {
        it('includes absolute URL in output', () => {
            const text = buildFreeWindowsTextAll([
                { date: '2025-09-27', ranges: [{ start: '12:00', end: '15:30' }] },
            ], TEST_URL);

            expect(text).toContain(`Записаться онлайн: ${TEST_URL}`);
        });

        it('does not contain relative URL', () => {
            const text = buildFreeWindowsTextAll([
                { date: '2025-09-27', ranges: [{ start: '12:00', end: '15:30' }] },
            ], TEST_URL);

            expect(text).not.toMatch(/Записаться онлайн: \//);
        });

        it('preserves template format', () => {
            const text = buildFreeWindowsTextAll([
                { date: '2025-09-27', ranges: [{ start: '12:00', end: '15:30' }] },
            ], TEST_URL);

            expect(text).toMatch(/^Свободное время на ближайшие дни\n\n27 сентября — 12:00–15:30\n\nЗаписаться онлайн:/);
        });
    });
});
