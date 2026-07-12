/**
 * Format raw phone digits to +7 (XXX) XXX-XX-XX
 */
export function formatPhone(raw: string | null): string {
    if (!raw) return '—';
    const digits = raw.replace(/\D/g, '');
    if (digits.length < 10) return raw;
    const d = digits.slice(-10);
    return `+7 (${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6, 8)}-${d.slice(8)}`;
}

/**
 * Strip mask from phone, leaving only digits (for DB storage)
 */
export function stripPhoneMask(value: string): string {
    return value.replace(/\D/g, '');
}
