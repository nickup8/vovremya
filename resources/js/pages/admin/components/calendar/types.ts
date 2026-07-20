import { AppointmentStatus } from '@/types/appointment-status';

export interface Appointment {
    id: number;
    client_name: string;
    client_phone: string | null;
    client_avatar_url: string | null;
    service: string;
    time: string;
    date: string;
    duration: number;
    price: number;
    status: AppointmentStatus;
}

export interface BlockedTime {
    id: number;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

export interface ClientOption {
    id: number;
    name: string;
    phone: string | null;
}

export interface ServiceOption {
    id: number;
    title: string;
    duration_minutes: number;
    price: number;
}

export interface AuthUser {
    id: number;
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

export interface WorkingHour {
    day_of_week: number;
    start_time: string | null;
    end_time: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    is_working: boolean;
}

export interface PageProps {
    appointments: Appointment[];
    initialBlockedTimes: BlockedTime[];
    clients: ClientOption[];
    services: ServiceOption[];
    slotInterval: number;
    workingHours: WorkingHour[];
    timezoneConfirmed: boolean;
    prefillClientId?: string;
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

export interface AppointmentWithCollision extends Appointment {
    colIndex: number;
    totalCols: number;
}
