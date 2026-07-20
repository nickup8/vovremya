import { AppointmentStatus } from '@/types/appointment-status';
import { STATUS_STYLES } from './constants';

const LEGEND_STATUSES = [
    AppointmentStatus.Booked,
    AppointmentStatus.PendingPayment,
    AppointmentStatus.Prepaid,
    AppointmentStatus.NoShow,
    AppointmentStatus.Paid,
    AppointmentStatus.Cancelled,
];

export function CalendarLegend() {
    return (
        <div className="flex flex-wrap gap-4 text-xs">
            {LEGEND_STATUSES.map((status) => (
                <div key={status} className="flex items-center gap-1.5">
                    <div className={`size-2.5 rounded-full ${STATUS_STYLES[status].dot}`} />
                    <span className="text-slate-500 dark:text-zinc-400">{STATUS_STYLES[status].label}</span>
                </div>
            ))}
        </div>
    );
}
