import { AppointmentStatus } from '@/types/appointment-status';

export interface StatusStyle {
    accent: string;
    bg: string;
    label: string;
    dot: string;
}

export const STATUS_STYLES: Record<AppointmentStatus, StatusStyle> = {
    [AppointmentStatus.Booked]: {
        accent: 'bg-[#3478F6]',
        bg: 'bg-[#EFF5FF] dark:bg-[#3478F6]/10',
        label: 'Записан',
        dot: 'bg-[#3478F6]',
    },
    [AppointmentStatus.PendingPayment]: {
        accent: 'bg-[#3478F6]',
        bg: 'bg-[#EFF5FF] dark:bg-[#3478F6]/10',
        label: 'Ожидает оплаты',
        dot: 'bg-[#3478F6]',
    },
    [AppointmentStatus.Prepaid]: {
        accent: 'bg-[#3478F6]',
        bg: 'bg-[#EFF5FF] dark:bg-[#3478F6]/10',
        label: 'Предоплата',
        dot: 'bg-[#3478F6]',
    },
    [AppointmentStatus.Paid]: {
        accent: 'bg-[#22A66F]',
        bg: 'bg-[#EFFAF5] dark:bg-[#22A66F]/10',
        label: 'Оплачен',
        dot: 'bg-[#22A66F]',
    },
    [AppointmentStatus.NoShow]: {
        accent: 'bg-[#E34F5F]',
        bg: 'bg-[#FFF1F3] dark:bg-[#E34F5F]/10',
        label: 'Неявка',
        dot: 'bg-[#E34F5F]',
    },
    [AppointmentStatus.Cancelled]: {
        accent: 'bg-[#92969D]',
        bg: 'bg-[#F3F4F5] dark:bg-[#92969D]/10',
        label: 'Отменён',
        dot: 'bg-[#92969D]',
    },
};

export const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
export const HOUR_HEIGHT = 80;
export const MINUTE_HEIGHT = HOUR_HEIGHT / 60;
