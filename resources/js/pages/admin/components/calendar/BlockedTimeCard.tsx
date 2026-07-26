import { BlockedTime } from './types';
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
            className="absolute inset-x-0 z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-zinc-300 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"
            style={{
                top,
                height,
                backgroundImage: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,0.03) 8px, rgba(0,0,0,0.03) 16px)',
            }}
        >
            <p className="truncate text-[10px] font-medium text-zinc-400 dark:text-zinc-500">
                {blockedTime.reason}
            </p>
        </div>
    );
}
