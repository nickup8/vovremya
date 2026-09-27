const FULL_MONTHS_RU = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

interface FreeWindowsDay {
    date: string;
    starts?: string[];
    ranges?: { start: string; end: string }[];
}

function formatDayRu(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return `${d} ${FULL_MONTHS_RU[m - 1]}`;
}

function formatRangeRu(range: { start: string; end: string }): string {
    return `${range.start}–${range.end}`;
}

/**
 * Build shareable text for free windows (service mode).
 *
 * Example output:
 * Свободное время на Маникюр
 *
 * 27 сентября — 12:00, 13:30, 18:00
 * 28 сентября — 11:00, 15:30
 *
 * Записаться онлайн: https://example.com/book/slug
 */
export function buildFreeWindowsTextService(
    serviceTitle: string,
    days: FreeWindowsDay[],
    bookingUrl: string,
): string {
    const lines: string[] = [];

    lines.push(`Свободное время на ${serviceTitle}`);
    lines.push('');

    for (const day of days) {
        if (day.starts && day.starts.length > 0) {
            lines.push(`${formatDayRu(day.date)} — ${day.starts.join(', ')}`);
        }
    }

    lines.push('');
    lines.push(`Записаться онлайн: ${bookingUrl}`);

    return lines.join('\n');
}

/**
 * Build shareable text for free windows (all-services mode).
 *
 * Example output:
 * Свободное время на ближайшие дни
 *
 * 27 сентября — 12:00–15:30, 18:00–20:00
 * 28 сентября — 10:00–13:00
 *
 * Записаться онлайн: https://example.com/book/slug
 */
export function buildFreeWindowsTextAll(
    days: FreeWindowsDay[],
    bookingUrl: string,
): string {
    const lines: string[] = [];

    lines.push('Свободное время на ближайшие дни');
    lines.push('');

    for (const day of days) {
        if (day.ranges && day.ranges.length > 0) {
            const rangeStrings = day.ranges.map(formatRangeRu);
            lines.push(`${formatDayRu(day.date)} — ${rangeStrings.join(', ')}`);
        }
    }

    lines.push('');
    lines.push(`Записаться онлайн: ${bookingUrl}`);

    return lines.join('\n');
}
