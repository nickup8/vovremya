import type { BlockedTime } from './types';
import { MINUTE_HEIGHT } from './constants';
import { timeToMinutes } from './helpers';

interface Props {
    blockedTime: BlockedTime;
    dayDate: string;
    dayStartHour: number;
    dayEndHour: number;
}

export function BlockedTimeCard({ blockedTime, dayDate, dayStartHour, dayEndHour }: Props) {
    const gridStartMin = dayStartHour * 60;
    const gridEndMin = dayEndHour * 60;

    const isFirstDay = dayDate === blockedTime.date;
    const isLastDay = dayDate === (blockedTime.end_date ?? blockedTime.date);

    const rawStartMin = isFirstDay ? timeToMinutes(blockedTime.start_time) : gridStartMin;
    const rawEndMin = isLastDay ? timeToMinutes(blockedTime.end_time) : gridEndMin;

    const clippedStart = Math.max(rawStartMin, gridStartMin);
    const clippedEnd = Math.min(rawEndMin, gridEndMin);

    if (clippedEnd <= clippedStart) {
        return null;
    }

    const top = (clippedStart - gridStartMin) * MINUTE_HEIGHT;
    const height = Math.max(clippedEnd - clippedStart, 15) * MINUTE_HEIGHT;

    return (
        <div
            className="absolute inset-x-0 z-0 mx-1 overflow-hidden rounded-[8px] bg-zinc-100/60 dark:bg-[var(--color-cal-surface-alt)]"
            style={{
                top,
                height,
                backgroundImage: 'repeating-linear-gradient(135deg, transparent, transparent 6px, rgba(0,0,0,0.02) 6px, rgba(0,0,0,0.02) 12px)',
            }}
        >
            <div className="absolute inset-y-0 left-0 w-[3px] rounded-l-[8px] bg-zinc-300 dark:bg-zinc-600/70" />
            <p className="truncate pl-3 pt-1 text-[10px] font-medium text-zinc-500 dark:text-zinc-400">
                {blockedTime.reason}
            </p>
        </div>
    );
}
