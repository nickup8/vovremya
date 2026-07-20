import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/lib/utils';
import { AppointmentWithCollision } from './types';
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
    const showPrice = appointment.duration >= 45;

    const { colIndex, totalCols } = appointment;
    const widthPercent = 100 / totalCols;
    const leftPercent = widthPercent * colIndex;

    return (
        <button
            onClick={onClick}
            className={`absolute z-10 cursor-pointer overflow-hidden rounded-lg border-l-4 px-2 py-1 shadow-xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${styles.card}`}
            style={{
                top,
                height: Math.max(height, 32),
                width: `calc(${widthPercent}% - 4px)`,
                left: `calc(${leftPercent}% + 2px)`,
            }}
        >
            <div className="flex h-full flex-col justify-between">
                <div>
                    <p className="font-mono text-[10px] opacity-75">
                        {appointment.time} – {endTime}
                    </p>
                    <div className="flex items-center gap-1">
                        <Avatar className="size-5 shrink-0">
                            <AvatarImage src={appointment.client_avatar_url ?? undefined} className="object-cover" />
                            <AvatarFallback className="text-[8px]">{getInitials(appointment.client_name)}</AvatarFallback>
                        </Avatar>
                        <p className="truncate text-xs font-semibold leading-tight">
                            {appointment.client_name}
                        </p>
                    </div>
                    <p className="truncate text-[11px] leading-tight opacity-80">
                        {appointment.service}
                    </p>
                </div>
                {showPrice && (
                    <p className="text-[10px] opacity-60">
                        {appointment.price.toLocaleString('ru-RU')} ₽
                    </p>
                )}
            </div>
        </button>
    );
}
