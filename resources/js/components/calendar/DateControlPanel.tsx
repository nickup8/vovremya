import { ChevronLeft, ChevronRight, CalendarDays, Plus } from 'lucide-react';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';

interface MasterOption {
    id: number;
    name: string;
}

interface DateControlPanelProps {
    viewMode: 'week' | 'month';
    dateLabel: string;
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onToggleView: () => void;
    onNewAppointment: () => void;
    masters?: MasterOption[];
    selectedMasterId?: string;
    onMasterChange?: (value: string) => void;
}

export default function DateControlPanel({
    viewMode,
    dateLabel,
    onPrev,
    onNext,
    onToday,
    onToggleView,
    onNewAppointment,
    masters = [],
    selectedMasterId = 'all',
    onMasterChange,
}: DateControlPanelProps) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div className="flex items-center gap-2">
                <button
                    onClick={onPrev}
                    className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                >
                    <ChevronLeft className="size-4 text-slate-600 dark:text-zinc-400" />
                </button>
                <h2 className="text-sm font-semibold text-slate-900 dark:text-zinc-100 md:text-base">
                    {dateLabel}
                </h2>
                <button
                    onClick={onNext}
                    className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                >
                    <ChevronRight className="size-4 text-slate-600 dark:text-zinc-400" />
                </button>
                <button
                    onClick={onToday}
                    className="ml-2 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    Сегодня
                </button>
            </div>
            <div className="flex items-center gap-2">
                {masters.length > 0 && onMasterChange && (
                    <Select value={selectedMasterId} onValueChange={onMasterChange}>
                        <SelectTrigger className="h-8 w-[180px] border-slate-200 bg-white text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <SelectValue placeholder="Все мастера" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Все мастера</SelectItem>
                            {masters.map(m => (
                                <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
                <button
                    onClick={onToggleView}
                    className="flex items-center gap-1.5 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    <CalendarDays className="size-3.5" />
                    {viewMode === 'week' ? 'Месяц' : 'Неделя'}
                </button>
                <button
                    onClick={onNewAppointment}
                    className="flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
                >
                    <Plus className="size-3.5" />
                    Новая запись
                </button>
            </div>
        </div>
    );
}
