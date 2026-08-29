import { AppointmentStatus } from '@/types/appointment-status';
import { STATUS_STYLES } from './constants';

const LEGEND_STATUSES = [
    AppointmentStatus.Booked,
    AppointmentStatus.Paid,
    AppointmentStatus.NoShow,
    AppointmentStatus.Cancelled,
];

export function CalendarLegend() {
    return (
        <div className="flex h-[44px] items-center gap-4 px-6 text-[11px]">
            {LEGEND_STATUSES.map((status) => (
                <div key={status} className="flex items-center gap-1.5">
                    <span className={`size-[6px] rounded-full ${STATUS_STYLES[status].dot}`} />
                    <span className="text-slate-400 dark:text-zinc-500">{STATUS_STYLES[status].label}</span>
                </div>
            ))}
        </div>
    );
}
