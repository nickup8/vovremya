import { AppointmentStatus } from '@/types/appointment-status';

export const STATUS_STYLES: Record<AppointmentStatus, { card: string; label: string; dot: string }> = {
    [AppointmentStatus.Booked]: {
        card: 'bg-blue-50 border-blue-500 text-blue-900 dark:bg-blue-950 dark:border-blue-500 dark:text-blue-200',
        label: 'Записан',
        dot: 'bg-blue-500',
    },
    [AppointmentStatus.PendingPayment]: {
        card: 'bg-amber-50 border-amber-500 text-amber-900 dark:bg-amber-950 dark:border-amber-500 dark:text-amber-200',
        label: 'Ожидает оплаты',
        dot: 'bg-amber-500',
    },
    [AppointmentStatus.Prepaid]: {
        card: 'bg-violet-50 border-violet-500 text-violet-900 dark:bg-violet-950 dark:border-violet-500 dark:text-violet-200',
        label: 'Предоплата',
        dot: 'bg-violet-500',
    },
    [AppointmentStatus.Paid]: {
        card: 'bg-emerald-50 border-emerald-500 text-emerald-900 dark:bg-emerald-950 dark:border-emerald-500 dark:text-emerald-200',
        label: 'Оплачен',
        dot: 'bg-emerald-500',
    },
    [AppointmentStatus.NoShow]: {
        card: 'bg-rose-50 border-rose-500 text-rose-900 dark:bg-rose-950 dark:border-rose-500 dark:text-rose-200',
        label: 'Неявка',
        dot: 'bg-rose-500',
    },
    [AppointmentStatus.Cancelled]: {
        card: 'bg-zinc-100 border-zinc-300 text-zinc-500 line-through dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-500',
        label: 'Отменён',
        dot: 'bg-zinc-400',
    },
};

export const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
export const HOUR_HEIGHT = 80;
export const MINUTE_HEIGHT = HOUR_HEIGHT / 60;
