import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import type { AppointmentWithCollision } from './types';
import { STATUS_STYLES } from './constants';
import { timeToMinutes, getEndTime } from './helpers';
import { MINUTE_HEIGHT } from './constants';

interface Props {
    appointment: AppointmentWithCollision;
    onClick: () => void;
    dayStartHour: number;
}

export function AppointmentCard({ appointment, onClick, dayStartHour }: Props) {
    const startMinutes = timeToMinutes(appointment.time) - dayStartHour * 60;
    const top = startMinutes * MINUTE_HEIGHT;
    const height = appointment.duration * MINUTE_HEIGHT;
    const styles = STATUS_STYLES[appointment.status];
    const endTime = getEndTime(appointment.time, appointment.duration);
    const isCancelled = appointment.status === 'cancelled';

    const { colIndex, totalCols } = appointment;
    const widthPercent = 100 / totalCols;
    const leftPercent = widthPercent * colIndex;

    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: appointment.id,
    });

    return (
        <button
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            onClick={onClick}
            className={`absolute z-10 flex cursor-pointer overflow-hidden rounded-[8px] text-left transition-shadow duration-150 hover:shadow-md ${styles.bg} ${isDragging ? 'opacity-40 shadow-lg' : 'shadow-xs'}`}
            style={{
                top,
                height: Math.max(height, 28),
                width: `calc(${widthPercent}% - 3px)`,
                left: `calc(${leftPercent}% + 1.5px)`,
                transform: CSS.Translate.toString(transform),
            }}
        >
            {/* 3px status marker */}
            <div className={`w-[3px] shrink-0 ${styles.accent}`} />

            <div className={`flex min-w-0 flex-1 flex-col justify-center px-2 py-[6px] ${isCancelled ? 'line-through opacity-60' : ''}`}>
                <span className="truncate font-mono text-[10px] leading-tight opacity-60">
                    {appointment.time}–{endTime}
                </span>
                <span className="truncate text-[12px] font-semibold leading-snug text-slate-800 dark:text-zinc-100">
                    {appointment.client_name}
                </span>
                {height >= 48 && (
                    <span className="truncate text-[11px] leading-tight opacity-50">
                        {appointment.service}
                    </span>
                )}
            </div>
        </button>
    );
}
