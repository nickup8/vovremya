import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { dateToKey } from './helpers';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    date: string;
    time: string;
    onDateChange: (date: string) => void;
    onTimeChange: (time: string) => void;
    onSubmit: () => void;
    timeOptions: string[];
}

export function RescheduleDialog({ open, onOpenChange, date, time, onDateChange, onTimeChange, onSubmit, timeOptions }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="text-slate-900 dark:text-zinc-100">
                        Перенос записи
                    </DialogTitle>
                    <DialogDescription className="text-slate-500 dark:text-zinc-400">
                        Выберите новую дату и время
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Дата
                        </label>
                        <input
                            type="date"
                            value={date}
                            min={dateToKey(new Date())}
                            onChange={(e) => onDateChange(e.target.value)}
                            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Время
                        </label>
                        <Select value={time} onValueChange={onTimeChange}>
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

                <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        className="flex-1 rounded-xl sm:flex-none"
                    >
                        Отмена
                    </Button>
                    <Button
                        onClick={onSubmit}
                        disabled={!date || !time}
                        className="flex-1 rounded-xl bg-blue-600 text-white hover:bg-blue-700 sm:flex-none"
                    >
                        Сохранить перенос
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
