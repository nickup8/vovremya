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
        <div className="flex h-[44px] items-center gap-[18px] overflow-x-auto whitespace-nowrap px-4 text-[11px] scrollbar-none lg:px-[22px]">
            {LEGEND_STATUSES.map((status) => (
                <div key={status} className="flex shrink-0 items-center gap-1.5">
                    <span className={`size-[7px] rounded-full ${STATUS_STYLES[status].dot}`} />
                    <span className="text-slate-400 dark:text-zinc-500">{STATUS_STYLES[status].label}</span>
                </div>
            ))}
        </div>
    );
}
