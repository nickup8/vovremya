import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Repeat } from 'lucide-react';
import { AppointmentStatus } from '@/types/appointment-status';
import type { AppointmentWithCollision } from './types';
import { STATUS_STYLES } from './constants';
import { timeToMinutes, getEndTime } from './helpers';
import { MINUTE_HEIGHT } from './constants';

const NON_DRAGGABLE_STATUSES = new Set([
    AppointmentStatus.Cancelled,
    AppointmentStatus.Paid,
    AppointmentStatus.NoShow,
]);

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
    const isCompact = height <= 40;
    const isDraggable = !NON_DRAGGABLE_STATUSES.has(appointment.status);

    const { colIndex, totalCols } = appointment;
    const widthPercent = 100 / totalCols;
    const leftPercent = widthPercent * colIndex;

    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: appointment.id,
        disabled: !isDraggable,
    });

    return (
        <button
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            onClick={onClick}
            className={`absolute z-10 flex overflow-hidden rounded-[8px] text-left transition-shadow duration-150 hover:shadow-md ${styles.bg} ${isDragging ? 'opacity-40 shadow-lg' : 'shadow-xs'} ${isDraggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-default'}`}
            style={{
                top,
                height: Math.max(height, 28),
                width: `calc(${widthPercent}% - 12px)`,
                left: `calc(${leftPercent}% + 6px)`,
                transform: CSS.Translate.toString(transform),
            }}
        >
            {/* 3px status marker — inset 5px top/bottom */}
            <div className={`w-[3px] shrink-0 self-stretch py-[5px]`}>
                <div className={`h-full w-full rounded-full ${styles.accent}`} />
            </div>

            <div className={`flex min-w-0 flex-1 flex-col justify-center pr-2 pl-[11px] ${isCompact ? 'py-[3px]' : 'py-[5px]'} ${isCancelled ? 'line-through opacity-60' : ''}`}>
                <span className={`flex items-center gap-0.5 truncate font-semibold tabular-nums opacity-60 ${isCompact ? 'text-[10px] leading-[12px]' : 'text-[10px] leading-[13px]'}`}>
                    {appointment.time}–{endTime}
                    {appointment.recurring_series_id && (
                        <Repeat className="size-[9px] shrink-0" />
                    )}
                </span>
                <span className={`truncate font-bold text-slate-800 dark:text-zinc-100 ${isCompact ? 'text-[12px] leading-[14px]' : 'mt-px text-[12px] leading-4'}`}>
                    {appointment.client_name}
                </span>
                {!isCompact && height >= 42 && (
                    <span className="mt-px truncate text-[10px] leading-[13px] opacity-50">
                        {appointment.service}
                    </span>
                )}
            </div>
        </button>
    );
}
