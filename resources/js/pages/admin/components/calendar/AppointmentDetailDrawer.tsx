import { useState, useEffect } from 'react';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel,
} from '@/components/ui/alert-dialog';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { formatPhone } from '@/lib/phone';
import { AppointmentStatus } from '@/types/appointment-status';
import { DAYS_RU_FULL, MONTHS_RU_GENITIVE } from '@/lib/locale';
import { Repeat } from 'lucide-react';
import type { Appointment } from './types';
import { STATUS_STYLES } from './constants';
import { getEndTime, dateToKey } from './helpers';

type CancelScope = 'single' | 'this-and-future' | 'whole-series';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selected: Appointment | null;
    isProcessing: boolean;
    onUpdateStatus: (status: AppointmentStatus) => void;
    onReschedule: () => void;
    onDelete: () => void;
    editMode?: boolean;
    onEditModeChange?: (editing: boolean) => void;
    editDate?: string;
    editTime?: string;
    onEditDateChange?: (d: string) => void;
    onEditTimeChange?: (t: string) => void;
    onEditSubmit?: () => void;
    timeOptions?: string[];
    isPro?: boolean;
    onRepeat?: () => void;
    onEditOnlyThis?: (date: string, time: string) => void;
    onEditThisAndFuture?: () => void;
    onCancelOnlyThis?: () => void;
    onCancelThisAndFuture?: () => void;
    onCancelWholeSeries?: () => void;
    onSeriesSettings?: () => void;
}

function formatDateLong(dateStr: string): string {
    const d = new Date(dateStr + 'T00:00:00');

    return `${DAYS_RU_FULL[d.getDay()]}, ${d.getDate()} ${MONTHS_RU_GENITIVE[d.getMonth()]} ${d.getFullYear()}`;
}

const AVAILABLE_TRANSITIONS: Partial<Record<AppointmentStatus, AppointmentStatus[]>> = {
    [AppointmentStatus.Booked]: [AppointmentStatus.Paid, AppointmentStatus.NoShow],
    [AppointmentStatus.PendingPayment]: [AppointmentStatus.Paid, AppointmentStatus.NoShow],
    [AppointmentStatus.Prepaid]: [AppointmentStatus.Paid, AppointmentStatus.NoShow],
    [AppointmentStatus.Paid]: [],
    [AppointmentStatus.NoShow]: [],
    [AppointmentStatus.Cancelled]: [],
};

const CANCEL_SCOPE_LABELS: Record<CancelScope, { title: string; description: string; action: string }> = {
    single: {
        title: 'Отменить запись?',
        description: 'Будет отменена только эта запись. Остальные записи серии не изменятся.',
        action: 'Да, отменить',
    },
    'this-and-future': {
        title: 'Отменить эту и следующие?',
        description: 'Будет отменена эта запись и все следующие записи серии.',
        action: 'Да, отменить',
    },
    'whole-series': {
        title: 'Отменить всю серию?',
        description: 'Будут отменены все активные записи этой серии. Прошедшие записи останутся в истории.',
        action: 'Да, отменить серию',
    },
};

export function AppointmentDetailDrawer({
    open, onOpenChange, selected, isProcessing,
    onUpdateStatus, onReschedule, onDelete,
    editMode = false, onEditModeChange,
    editDate = '', editTime = '',
    onEditDateChange, onEditTimeChange,
    onEditSubmit, timeOptions = [],
    isPro = false, onRepeat,
    onEditOnlyThis, onEditThisAndFuture,
    onCancelOnlyThis, onCancelThisAndFuture, onCancelWholeSeries,
    onSeriesSettings,
}: Props) {
    const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);
    const [cancelScope, setCancelScope] = useState<CancelScope>('single');
    const [cancelScopeOpen, setCancelScopeOpen] = useState(false);
    const [statusPopoverOpen, setStatusPopoverOpen] = useState(false);
    const [editMenuOpen, setEditMenuOpen] = useState(false);

    const isRecurring = !!selected?.recurring_series_id;

    useEffect(() => {
        if (!open) {
            if (onEditModeChange) {
                onEditModeChange(false);
            }
            setEditMenuOpen(false);
            setCancelScopeOpen(false);
        }
    }, [open]);

    function handleCancelConfirm() {
        setCancelConfirmOpen(false);
        if (cancelScope === 'single' && isRecurring) {
            onCancelOnlyThis?.();
        } else if (cancelScope === 'this-and-future') {
            onCancelThisAndFuture?.();
        } else if (cancelScope === 'whole-series') {
            onCancelWholeSeries?.();
        } else {
            onDelete();
        }
        setCancelScope('single');
    }

    function handleCancelClick() {
        if (isRecurring) {
            setCancelScopeOpen(true);
        } else {
            setCancelScope('single');
            setCancelConfirmOpen(true);
        }
    }

    function handleEditClick() {
        if (onEditModeChange) {
            onEditModeChange(true);
        } else {
            onReschedule();
        }
    }

    function handleEditCancel() {
        if (onEditModeChange) {
            onEditModeChange(false);
        }
    }

    function handleEditSave() {
        if (onEditSubmit) {
            onEditSubmit();
        }
    }

    const scopeInfo = CANCEL_SCOPE_LABELS[cancelScope];
    const transitions = selected ? (AVAILABLE_TRANSITIONS[selected.status] ?? []) : [];
    const canChangeStatus = selected && selected.status !== AppointmentStatus.Cancelled && transitions.length > 0;
    const canEdit = selected && selected.status !== AppointmentStatus.Cancelled;

    return (
        <>
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerContent>
                    {selected && (
                        <>
                            <DrawerHeader>
                                <div className="flex items-center justify-between pr-8">
                                    <h2 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                                        {editMode ? 'Редактировать запись' : 'Запись'}
                                    </h2>
                                </div>
                            </DrawerHeader>

                            <DrawerBody>
                                {editMode ? (
                                    /* ─── Edit State ─── */
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-3">
                                            <Avatar className="size-10 shrink-0">
                                                <AvatarImage src={selected.client_avatar_url ?? undefined} className="object-cover" />
                                                <AvatarFallback className="text-xs">{getInitials(selected.client_name)}</AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                    {selected.client_name}
                                                </p>
                                                {selected.client_phone && (
                                                    <p className="text-sm text-slate-500 dark:text-zinc-400">
                                                        {formatPhone(selected.client_phone)}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-3">
                                            <div>
                                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                    Дата
                                                </label>
                                                <input
                                                    type="date"
                                                    value={editDate}
                                                    min={dateToKey(new Date())}
                                                    onChange={(e) => onEditDateChange?.(e.target.value)}
                                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                    Время
                                                </label>
                                                <Select value={editTime} onValueChange={(v) => onEditTimeChange?.(v)}>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="Выберите время" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {timeOptions.map((t) => (
                                                            <SelectItem key={t} value={t}>{t}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    /* ─── Detail State ─── */
                                    <div className="space-y-6">
                                        <div className="flex items-center gap-3">
                                            <Avatar className="size-10 shrink-0">
                                                <AvatarImage src={selected.client_avatar_url ?? undefined} className="object-cover" />
                                                <AvatarFallback className="text-xs">{getInitials(selected.client_name)}</AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                    {selected.client_name}
                                                </p>
                                                {selected.client_phone && (
                                                    <a
                                                        href={`tel:+${selected.client_phone.replace(/\D/g, '')}`}
                                                        className="text-sm text-slate-500 hover:text-blue-600 dark:text-zinc-400 dark:hover:text-blue-400"
                                                    >
                                                        {formatPhone(selected.client_phone)}
                                                    </a>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-3 text-sm">
                                            <div className="flex items-baseline justify-between">
                                                <span className="text-slate-500 dark:text-zinc-400">Время</span>
                                                <span className="font-medium text-slate-800 dark:text-zinc-200">
                                                    {formatDateLong(selected.date)}, {selected.time}–{getEndTime(selected.time, selected.duration)}
                                                </span>
                                            </div>
                                            <div className="flex items-baseline justify-between">
                                                <span className="text-slate-500 dark:text-zinc-400">Услуга</span>
                                                <span className="font-medium text-slate-800 dark:text-zinc-200">{selected.service}</span>
                                            </div>
                                            <div className="flex items-baseline justify-between">
                                                <span className="text-slate-500 dark:text-zinc-400">Стоимость</span>
                                                <span className="font-medium text-slate-800 dark:text-zinc-200">
                                                    {selected.price.toLocaleString('ru-RU')} ₽
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-slate-500 dark:text-zinc-400">Статус</span>
                                                <div className="flex items-center gap-2">
                                                    {isRecurring && (
                                                        <span className="inline-flex items-center gap-1 text-xs text-slate-400 dark:text-zinc-500">
                                                            <Repeat className="size-3" />
                                                            Серия
                                                        </span>
                                                    )}
                                                    {canChangeStatus ? (
                                                        <Popover open={statusPopoverOpen} onOpenChange={setStatusPopoverOpen}>
                                                            <PopoverTrigger asChild>
                                                                <button
                                                                    type="button"
                                                                    className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors hover:opacity-80 ${STATUS_STYLES[selected.status].bg}`}
                                                                >
                                                                    <span className={`size-1.5 rounded-full ${STATUS_STYLES[selected.status].dot}`} />
                                                                    <span className="text-slate-700 dark:text-zinc-300">
                                                                        {STATUS_STYLES[selected.status].label}
                                                                    </span>
                                                                </button>
                                                            </PopoverTrigger>
                                                            <PopoverContent align="end" sideOffset={4} className="w-48 p-1">
                                                                {transitions.map((status) => (
                                                                    <button
                                                                        key={status}
                                                                        type="button"
                                                                        disabled={isProcessing}
                                                                        onClick={() => {
                                                                            setStatusPopoverOpen(false);
                                                                            onUpdateStatus(status);
                                                                        }}
                                                                        className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                                    >
                                                                        <span className={`size-2 rounded-full ${STATUS_STYLES[status].dot}`} />
                                                                        {STATUS_STYLES[status].label}
                                                                    </button>
                                                                ))}
                                                            </PopoverContent>
                                                        </Popover>
                                                    ) : (
                                                        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_STYLES[selected.status].bg}`}>
                                                            <span className={`size-1.5 rounded-full ${STATUS_STYLES[selected.status].dot}`} />
                                                            <span className="text-slate-700 dark:text-zinc-300">
                                                                {STATUS_STYLES[selected.status].label}
                                                            </span>
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {canChangeStatus && (
                                            <div className="flex gap-2 pt-1">
                                                {selected.status !== AppointmentStatus.Paid && (
                                                    <Button
                                                        onClick={() => onUpdateStatus(AppointmentStatus.Paid)}
                                                        disabled={isProcessing}
                                                        size="sm"
                                                        className="flex-1 rounded-lg bg-[#22A66F] text-white text-xs hover:bg-[#1d9460]"
                                                    >
                                                        Оплачено
                                                    </Button>
                                                )}
                                                {selected.status !== AppointmentStatus.NoShow && (
                                                    <Button
                                                        onClick={() => onUpdateStatus(AppointmentStatus.NoShow)}
                                                        disabled={isProcessing}
                                                        size="sm"
                                                        variant="outline"
                                                        className="flex-1 rounded-lg border-[#E34F5F]/30 text-[#E34F5F] text-xs hover:bg-[#E34F5F]/5"
                                                    >
                                                        Не пришёл
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </DrawerBody>

                            {editMode ? (
                                <DrawerFooter>
                                    <div className="flex gap-3">
                                        <Button
                                            onClick={handleEditCancel}
                                            disabled={isProcessing}
                                            variant="outline"
                                            className="flex-1 rounded-lg"
                                        >
                                            Отмена
                                        </Button>
                                        <Button
                                            onClick={handleEditSave}
                                            disabled={isProcessing || !editDate || !editTime}
                                            className="flex-1 rounded-lg bg-[var(--color-orange)] text-white hover:bg-[var(--color-orange-600)]"
                                        >
                                            Сохранить
                                        </Button>
                                    </div>
                                </DrawerFooter>
                            ) : canEdit ? (
                                <DrawerFooter>
                                    {/* Row 1: Изменить + Повторять */}
                                    <div className="flex gap-3">
                                        {isRecurring ? (
                                            <Popover open={editMenuOpen} onOpenChange={setEditMenuOpen}>
                                                <PopoverTrigger asChild>
                                                    <Button
                                                        disabled={isProcessing}
                                                        variant="outline"
                                                        className="flex-1 rounded-lg"
                                                    >
                                                        Изменить
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent align="start" sideOffset={4} className="w-52 p-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => { setEditMenuOpen(false); handleEditClick(); }}
                                                        className="flex w-full items-center rounded-md px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                    >
                                                        Только эту
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => { setEditMenuOpen(false); onEditThisAndFuture?.(); }}
                                                        className="flex w-full items-center rounded-md px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                    >
                                                        Эту и следующие
                                                    </button>
                                                    {onSeriesSettings && (
                                                        <button
                                                            type="button"
                                                            onClick={() => { setEditMenuOpen(false); onSeriesSettings(); }}
                                                            className="flex w-full items-center rounded-md px-3 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                        >
                                                            Настройки серии
                                                        </button>
                                                    )}
                                                </PopoverContent>
                                            </Popover>
                                        ) : (
                                            <Button
                                                onClick={handleEditClick}
                                                disabled={isProcessing}
                                                variant="outline"
                                                className="flex-1 rounded-lg"
                                            >
                                                Изменить
                                            </Button>
                                        )}
                                        {isPro && onRepeat && (
                                            <Button
                                                onClick={onRepeat}
                                                disabled={isProcessing}
                                                variant="outline"
                                                className="flex-1 rounded-lg"
                                            >
                                                Повторять
                                            </Button>
                                        )}
                                    </div>
                                    {/* Row 2: Отменить запись — full width */}
                                    <Button
                                        onClick={handleCancelClick}
                                        disabled={isProcessing}
                                        variant="outline"
                                        className="w-full rounded-lg border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                    >
                                        Отменить запись
                                    </Button>
                                </DrawerFooter>
                            ) : null}
                        </>
                    )}
                </DrawerContent>
            </Drawer>

            {/* Cancel scope selector (recurring only) */}
            <AlertDialog open={cancelScopeOpen} onOpenChange={setCancelScopeOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Отменить запись</AlertDialogTitle>
                        <AlertDialogDescription>
                            Выберите масштаб отмены:
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="flex flex-col gap-2 py-2">
                        <Button
                            variant="outline"
                            className="justify-start rounded-lg text-sm"
                            onClick={() => { setCancelScopeOpen(false); setCancelScope('single'); setCancelConfirmOpen(true); }}
                            disabled={isProcessing}
                        >
                            Только эту запись
                        </Button>
                        <Button
                            variant="outline"
                            className="justify-start rounded-lg text-sm"
                            onClick={() => { setCancelScopeOpen(false); setCancelScope('this-and-future'); setCancelConfirmOpen(true); }}
                            disabled={isProcessing}
                        >
                            Эту и следующие
                        </Button>
                        <Button
                            variant="outline"
                            className="justify-start rounded-lg text-sm"
                            onClick={() => { setCancelScopeOpen(false); setCancelScope('whole-series'); setCancelConfirmOpen(true); }}
                            disabled={isProcessing}
                        >
                            Всю серию
                        </Button>
                    </div>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isProcessing}>Отмена</AlertDialogCancel>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Cancel confirmation */}
            <AlertDialog open={cancelConfirmOpen} onOpenChange={setCancelConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{scopeInfo.title}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {scopeInfo.description}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isProcessing}>Нет, оставить</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleCancelConfirm}
                            disabled={isProcessing}
                            className="bg-red-600 text-white hover:bg-red-700 dark:bg-red-700 dark:hover:bg-red-800"
                        >
                            {scopeInfo.action}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
