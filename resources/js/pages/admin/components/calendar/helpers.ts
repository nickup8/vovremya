import { MONTHS_RU_GENITIVE } from '@/lib/locale';
import { Appointment } from './types';

export function timeToMinutes(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}

export function getEndTime(time: string, duration: number): string {
    const total = timeToMinutes(time) + duration;
    const h = Math.floor(total / 60);
    const m = total % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

export function getWeekDates(center: Date): Date[] {
    const start = new Date(center);
    const day = start.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    start.setDate(start.getDate() + diff);
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        return d;
    });
}

export function formatDateRange(dates: Date[]): string {
    const first = dates[0];
    const last = dates[6];
    if (first.getMonth() === last.getMonth()) {
        return `${first.getDate()} – ${last.getDate()} ${MONTHS_RU_GENITIVE[first.getMonth()]} ${first.getFullYear()}`;
    }
    return `${first.getDate()} ${MONTHS_RU_GENITIVE[first.getMonth()]} – ${last.getDate()} ${MONTHS_RU_GENITIVE[last.getMonth()]} ${first.getFullYear()}`;
}

export function dateToKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function isSameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function hasCollision(date: string, startTime: string, durationMinutes: number, appointments: Appointment[]): boolean {
    const start = timeToMinutes(startTime);
    const end = start + durationMinutes;
    return appointments.some((a) => {
        if (a.date !== date) return false;
        const aStart = timeToMinutes(a.time);
        const aEnd = aStart + a.duration;
        return start < aEnd && end > aStart;
    });
}

export function getMonthGrid(center: Date): Date[] {
    const year = center.getFullYear();
    const month = center.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    const startDow = firstDay.getDay();
    const gridStart = new Date(firstDay);
    gridStart.setDate(gridStart.getDate() - (startDow === 0 ? 6 : startDow - 1));

    const totalCells = Math.ceil(((lastDay.getTime() - gridStart.getTime()) / 86400000 + 1) / 7) * 7;

    return Array.from({ length: totalCells }, (_, i) => {
        const d = new Date(gridStart);
        d.setDate(d.getDate() + i);
        return d;
    });
}
