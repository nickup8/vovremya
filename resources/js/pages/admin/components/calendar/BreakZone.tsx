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
            className="absolute inset-x-0 z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-amber-200 bg-amber-50 px-2 py-1 dark:border-amber-800 dark:bg-amber-950/50"
            style={{
                top,
                height: Math.max(height, 24),
                backgroundImage: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,0.03) 8px, rgba(0,0,0,0.03) 16px)',
            }}
        >
            <p className="truncate text-[10px] font-medium text-amber-500 dark:text-amber-400">
                Обед
            </p>
        </div>
    );
}
