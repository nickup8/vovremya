import {
    CalendarDays, Clock, User, Phone,
    CheckCircle2, XCircle, Trash2, RotateCw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import { formatPhone } from '@/lib/phone';
import { AppointmentStatus } from '@/types/appointment-status';
import { Appointment } from './types';
import { STATUS_STYLES } from './constants';
import { DAYS_RU_FULL, MONTHS_RU_GENITIVE } from '@/lib/locale';
import { getEndTime } from './helpers';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selected: Appointment | null;
    isProcessing: boolean;
    onUpdateStatus: (status: AppointmentStatus) => void;
    onReschedule: () => void;
    onDelete: () => void;
}

function formatDateLong(dateStr: string): string {
    const d = new Date(dateStr + 'T00:00:00');
    return `${DAYS_RU_FULL[d.getDay()]}, ${d.getDate()} ${MONTHS_RU_GENITIVE[d.getMonth()]} ${d.getFullYear()}`;
}

export function AppointmentDetailDialog({ open, onOpenChange, selected, isProcessing, onUpdateStatus, onReschedule, onDelete }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                {selected && (
                    <>
                        <DialogHeader className="pb-2">
                            <DialogTitle className="text-lg text-slate-900 dark:text-zinc-100">
                                Детали записи
                            </DialogTitle>
                            <DialogDescription className="text-slate-500 dark:text-zinc-400">
                                {STATUS_STYLES[selected.status].label}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                <CalendarDays className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                {formatDateLong(selected.date)}
                            </div>
                            <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                <Clock className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                {selected.time} — {getEndTime(selected.time, selected.duration)}
                                <span className="text-xs text-slate-400 dark:text-zinc-500">({selected.duration} мин)</span>
                            </div>
                            <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                <User className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                {selected.client_name}
                            </div>
                            {selected.client_phone && (
                                <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                    <Phone className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                    <a href={`tel:+${selected.client_phone.replace(/\D/g, '')}`} className="hover:text-blue-600 dark:hover:text-blue-400">
                                        {formatPhone(selected.client_phone)}
                                    </a>
                                </div>
                            )}
                            <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                <span className="size-4 shrink-0 text-center text-sm font-bold text-slate-400 dark:text-zinc-500">₽</span>
                                {selected.service} — {selected.price.toLocaleString('ru-RU')} ₽
                            </div>
                        </div>

                        <div className="flex flex-col gap-2 pt-2">
                            {selected.status !== AppointmentStatus.Paid && selected.status !== AppointmentStatus.Cancelled && (
                                <Button
                                    onClick={() => onUpdateStatus(AppointmentStatus.Paid)}
                                    disabled={isProcessing}
                                    className="w-full justify-start rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"
                                >
                                    <CheckCircle2 className="size-4" />
                                    Оплата получена
                                </Button>
                            )}
                            {selected.status !== AppointmentStatus.NoShow && selected.status !== AppointmentStatus.Cancelled && (
                                <Button
                                    onClick={() => onUpdateStatus(AppointmentStatus.NoShow)}
                                    disabled={isProcessing}
                                    variant="outline"
                                    className="w-full justify-start rounded-lg border-rose-200 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                >
                                    <XCircle className="size-4" />
                                    Не пришёл
                                </Button>
                            )}
                            {selected.status !== AppointmentStatus.Cancelled && (
                                <>
                                    <Button
                                        onClick={onReschedule}
                                        disabled={isProcessing}
                                        variant="outline"
                                        className="w-full justify-start rounded-lg"
                                    >
                                        <RotateCw className="size-4" />
                                        Перенести запись
                                    </Button>
                                    <Button
                                        onClick={onDelete}
                                        disabled={isProcessing}
                                        variant="outline"
                                        className="w-full justify-start rounded-lg border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                    >
                                        <Trash2 className="size-4" />
                                        Удалить бронь
                                    </Button>
                                </>
                            )}
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
