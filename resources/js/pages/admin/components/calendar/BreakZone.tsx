import { timeToMinutes } from './helpers';
import { MINUTE_HEIGHT } from './constants';

interface Props {
    breakStart: string;
    breakEnd: string;
    dayStartHour: number;
}

export function BreakZone({ breakStart, breakEnd, dayStartHour }: Props) {
    const startMinutes = timeToMinutes(breakStart) - dayStartHour * 60;
    const endMinutes = timeToMinutes(breakEnd) - dayStartHour * 60;
    const durationMinutes = Math.max(endMinutes - startMinutes, 15);
    const top = startMinutes * MINUTE_HEIGHT;
    const height = durationMinutes * MINUTE_HEIGHT;

    return (
        <div
            className="absolute inset-x-0 z-0 mx-1 overflow-hidden rounded-[8px] bg-amber-50/60 dark:bg-[var(--color-cal-surface-alt)]"
            style={{
                top,
                height: Math.max(height, 24),
                backgroundImage: 'repeating-linear-gradient(135deg, transparent, transparent 6px, rgba(0,0,0,0.025) 6px, rgba(0,0,0,0.025) 12px)',
            }}
        >
            {/* 3px orange/amber left marker */}
            <div className="absolute inset-y-0 left-0 w-[3px] rounded-l-[8px] bg-amber-400 dark:bg-amber-500/70" />
            <p className="truncate pl-3 pt-1 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                Обед
            </p>
        </div>
    );
}
