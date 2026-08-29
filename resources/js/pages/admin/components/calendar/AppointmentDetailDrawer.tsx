import { useState } from 'react';
import {
    CalendarDays, Clock, User, Phone,
    CheckCircle2, XCircle, Trash2, RotateCw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerDescription,
    DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel,
} from '@/components/ui/alert-dialog';
import { formatPhone } from '@/lib/phone';
import { AppointmentStatus } from '@/types/appointment-status';
import { DAYS_RU_FULL, MONTHS_RU_GENITIVE } from '@/lib/locale';
import type { Appointment } from './types';
import { STATUS_STYLES } from './constants';
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

export function AppointmentDetailDrawer({ open, onOpenChange, selected, isProcessing, onUpdateStatus, onReschedule, onDelete }: Props) {
    const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);

    function handleCancelConfirm() {
        setCancelConfirmOpen(false);
        onDelete();
    }

    return (
        <>
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerContent>
                    {selected && (
                        <>
                            <DrawerHeader>
                                <div className="flex items-center gap-2">
                                    <DrawerTitle className="text-slate-900 dark:text-zinc-100">
                                        {selected.client_name}
                                    </DrawerTitle>
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[selected.status].bg} ${STATUS_STYLES[selected.status].dot.replace('bg-', 'text-')}`}>
                                        {STATUS_STYLES[selected.status].label}
                                    </span>
                                </div>
                                <DrawerDescription className="text-slate-500 dark:text-zinc-400">
                                    {selected.service}
                                </DrawerDescription>
                            </DrawerHeader>

                            <DrawerBody>
                                <div className="space-y-4">
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
                                        {selected.master_name && (
                                            <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                                <User className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                                Мастер: {selected.master_name}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </DrawerBody>

                            <DrawerFooter>
                                {selected.status !== AppointmentStatus.Paid && selected.status !== AppointmentStatus.Cancelled && (
                                    <Button
                                        onClick={() => onUpdateStatus(AppointmentStatus.Paid)}
                                        disabled={isProcessing}
                                        className="justify-start rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"
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
                                        className="justify-start rounded-lg border-rose-200 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400 dark:hover:bg-rose-950/40"
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
                                            className="justify-start rounded-lg"
                                        >
                                            <RotateCw className="size-4" />
                                            Перенести запись
                                        </Button>
                                        <Button
                                            onClick={() => setCancelConfirmOpen(true)}
                                            disabled={isProcessing}
                                            variant="outline"
                                            className="justify-start rounded-lg border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                        >
                                            <Trash2 className="size-4" />
                                            Отменить запись
                                        </Button>
                                    </>
                                )}
                            </DrawerFooter>
                        </>
                    )}
                </DrawerContent>
            </Drawer>

            {/* Cancel Confirmation AlertDialog */}
            <AlertDialog open={cancelConfirmOpen} onOpenChange={setCancelConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Отменить запись?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {selected && (
                                <>Запись {selected.client_name} на {selected.time} ({selected.service}) будет отменена. Карточка останется в календаре со статусом «Отменён».</>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isProcessing}>Нет, оставить</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleCancelConfirm}
                            disabled={isProcessing}
                            className="bg-red-600 text-white hover:bg-red-700 dark:bg-red-700 dark:hover:bg-red-800"
                        >
                            Да, отменить
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
