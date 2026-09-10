export interface EarlierRequest {
    id: string;
    date_from: string;
    date_to: string;
    time_from: string;
    time_to: string;
    status: string;
}

export interface Appointment {
    id: string;
    service: string;
    price: number;
    status: string;
    start_at: string;
    start_at_human: string;
    master: { name: string; address: string | null; phone: string | null; master_slug: string | null } | null;
    can_cancel: boolean;
    autofill_available: boolean;
    earlier_request: EarlierRequest | null;
}

export interface Profile {
    name: string | null;
    phone: string | null;
}

export class UnauthorizedError extends Error {
    constructor() {
        super('unauthorized');
        this.name = 'UnauthorizedError';
    }
}
