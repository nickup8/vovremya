import { AppointmentStatus } from '@/types/appointment-status';

export interface Appointment {
    id: string;
    client_name: string;
    client_phone: string | null;
    client_avatar_url: string | null;
    service: string;
    time: string;
    date: string;
    duration: number;
    price: number;
    status: AppointmentStatus;
    master_id?: string;
    master_name?: string;
}

export interface BlockedTime {
    id: string;
    date: string;
    end_date: string;
    start_time: string;
    end_time: string;
    reason: string;
    user_id?: string;
}

export interface ClientOption {
    id: string;
    name: string;
    phone: string | null;
}

export interface ServiceOption {
    id: string;
    title: string;
    duration_minutes: number;
    price: number;
    user_id: string;
}

export interface AuthUser {
    id: string;
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

export interface WorkingHour {
    user_id?: string;
    day_of_week: number;
    start_time: string | null;
    end_time: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    is_working: boolean;
}

export interface MasterOption {
    id: string;
    name: string;
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
    masters?: MasterOption[];
    [key: string]: unknown;
}

export interface AppointmentWithCollision extends Appointment {
    colIndex: number;
    totalCols: number;
}
