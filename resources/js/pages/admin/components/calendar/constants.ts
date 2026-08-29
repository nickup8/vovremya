import { AppointmentStatus } from '@/types/appointment-status';

export interface StatusStyle {
    accent: string;
    bg: string;
    label: string;
    dot: string;
}

function makeCard(accent: string, bg: string, label: string, dot: string): StatusStyle {
    return { accent, bg, label, dot };
}

export const STATUS_STYLES: Record<AppointmentStatus, StatusStyle> = {
    [AppointmentStatus.Booked]: makeCard(
        'bg-blue-500',
        'bg-blue-50 dark:bg-blue-950/40',
        'Записан',
        'bg-blue-500',
    ),
    [AppointmentStatus.PendingPayment]: makeCard(
        'bg-blue-500',
        'bg-blue-50 dark:bg-blue-950/40',
        'Ожидает оплаты',
        'bg-amber-500',
    ),
    [AppointmentStatus.Prepaid]: makeCard(
        'bg-blue-500',
        'bg-blue-50 dark:bg-blue-950/40',
        'Предоплата',
        'bg-violet-500',
    ),
    [AppointmentStatus.Paid]: makeCard(
        'bg-emerald-500',
        'bg-emerald-50 dark:bg-emerald-950/40',
        'Оплачен',
        'bg-emerald-500',
    ),
    [AppointmentStatus.NoShow]: makeCard(
        'bg-red-500',
        'bg-red-50 dark:bg-red-950/40',
        'Неявка',
        'bg-red-500',
    ),
    [AppointmentStatus.Cancelled]: makeCard(
        'bg-zinc-400 dark:bg-zinc-600',
        'bg-zinc-100 dark:bg-zinc-800/60',
        'Отменён',
        'bg-zinc-400',
    ),
};

export const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
export const HOUR_HEIGHT = 80;
export const MINUTE_HEIGHT = HOUR_HEIGHT / 60;
