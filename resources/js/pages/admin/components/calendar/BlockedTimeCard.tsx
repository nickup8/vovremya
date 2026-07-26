import { BlockedTime } from './types';
import { MINUTE_HEIGHT } from './constants';
import { timeToMinutes } from './helpers';

interface Props {
    blockedTime: BlockedTime;
    dayDate: string;
    dayStartHour: number;
}

export function BlockedTimeCard({ blockedTime, dayStartHour }: Props) {
    const startMinutes = timeToMinutes(blockedTime.start_time) - dayStartHour * 60;
    const endMinutes = timeToMinutes(blockedTime.end_time) - dayStartHour * 60;
    const durationMinutes = Math.max(endMinutes - startMinutes, 15);

    const top = startMinutes * MINUTE_HEIGHT;
    const height = durationMinutes * MINUTE_HEIGHT;

    return (
        <div
            className="absolute inset-x-0 z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-zinc-300 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"
            style={{
                top,
                height: Math.max(height, 24),
                backgroundImage: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,0.03) 8px, rgba(0,0,0,0.03) 16px)',
            }}
        >
            <p className="truncate text-[10px] font-medium text-zinc-400 dark:text-zinc-500">
                {blockedTime.reason}
            </p>
        </div>
    );
}
