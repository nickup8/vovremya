import { BlockedTime } from './types';
import { MINUTE_HEIGHT } from './constants';

interface Props {
    blockedTime: BlockedTime;
    dayDate: string;
    dayStartHour: number;
}

export function BlockedTimeCard({ blockedTime, dayDate, dayStartHour }: Props) {
    const btStart = new Date(blockedTime.start_datetime);
    const btEnd = new Date(blockedTime.end_datetime);

    const dayStart = new Date(dayDate + 'T00:00:00');
    const dayEnd = new Date(dayDate + 'T23:59:59');

    const effectiveStart = btStart < dayStart ? dayStart : btStart;
    const effectiveEnd = btEnd > dayEnd ? dayEnd : btEnd;

    const startMinutes = effectiveStart.getHours() * 60 + effectiveStart.getMinutes() - dayStartHour * 60;
    const endMinutes = effectiveEnd.getHours() * 60 + effectiveEnd.getMinutes() - dayStartHour * 60;
    const durationMinutes = Math.max(endMinutes - startMinutes, 15);

    const top = startMinutes * MINUTE_HEIGHT;
    const height = durationMinutes * MINUTE_HEIGHT;

    return (
        <div
            className="absolute z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-zinc-300 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"
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
