import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { dateToKey } from './helpers';
import { ClientOption, ServiceOption } from './types';

interface FormData {
    client_id: string;
    service_id: string;
    date: string;
    time: string;
    ignore_warnings: boolean;
    confirm_outside_hours: boolean;
}

interface FormErrors {
    client_id?: string;
    service_id?: string;
    date?: string;
    time?: string;
    [key: string]: string | undefined;
}

interface FormMethods {
    data: FormData;
    errors: FormErrors;
    processing: boolean;
    setData: (key: keyof FormData, value: string | boolean) => void;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: FormMethods;
    clients: ClientOption[];
    services: ServiceOption[];
    onSubmit: (e: React.FormEvent) => void;
    slotInterval: number;
}

export function NewAppointmentDialog({ open, onOpenChange, form, clients, services, onSubmit, slotInterval }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                <form onSubmit={onSubmit}>
                    <DialogHeader>
                        <DialogTitle className="text-slate-900 dark:text-zinc-100">
                            Новая запись
                        </DialogTitle>
                        <DialogDescription className="text-slate-500 dark:text-zinc-400">
                            Выберите клиента, услугу и время
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Клиент *
                            </label>
                            <Select
                                value={form.data.client_id}
                                onValueChange={(value) => form.setData('client_id', value)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Выберите клиента" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}{c.phone ? ` (${c.phone})` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.client_id && (
                                <p className="mt-1 text-xs text-red-500">{form.errors.client_id}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Услуга *
                            </label>
                            <Select
                                value={form.data.service_id}
                                onValueChange={(value) => form.setData('service_id', value)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Выберите услугу" />
                                </SelectTrigger>
                                <SelectContent>
                                    {services.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.title} — {s.duration_minutes} мин, {s.price.toLocaleString('ru-RU')} ₽
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.service_id && (
                                <p className="mt-1 text-xs text-red-500">{form.errors.service_id}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Дата *
                                </label>
                                <input
                                    type="date"
                                    value={form.data.date}
                                    min={dateToKey(new Date())}
                                    onChange={(e) => form.setData('date', e.target.value)}
                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                                {form.errors.date && (
                                    <p className="mt-1 text-xs text-red-500">{form.errors.date}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Время *
                                </label>
                                <input
                                    type="time"
                                    value={form.data.time}
                                    onChange={(e) => form.setData('time', e.target.value)}
                                    step={slotInterval * 60}
                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                                {form.errors.time && (
                                    <p className="mt-1 text-xs text-red-500">{form.errors.time}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            className="flex-1 rounded-xl sm:flex-none"
                        >
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.client_id || !form.data.service_id || !form.data.date || !form.data.time}
                            className="flex-1 rounded-xl bg-blue-600 text-white hover:bg-blue-700 sm:flex-none"
                        >
                            {form.processing ? 'Создание...' : 'Создать запись'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
