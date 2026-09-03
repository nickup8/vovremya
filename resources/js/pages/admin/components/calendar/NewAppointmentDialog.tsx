import { Button } from '@/components/ui/button';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerDescription, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { dateToKey } from './helpers';
import type { ClientOption, MasterOption, ServiceOption } from './types';
import { ClientCombobox } from './ClientCombobox';
import { IrsiDatePicker } from './IrsiDatePicker';
import { IrsiTimeSelect } from './IrsiTimeSelect';

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
    masters: MasterOption[];
    preselectedMasterId?: string | null;
    onSubmit: (e: React.FormEvent) => void;
    slotInterval: number;
    onClientCreated: (client: ClientOption) => void;
    timeOptions: string[];
}

export function NewAppointmentDialog({ open, onOpenChange, form, clients, services, masters: _masters, preselectedMasterId, onSubmit, slotInterval, onClientCreated, timeOptions }: Props) {
    const visibleServices = preselectedMasterId
        ? services.filter((s) => s.master_id === preselectedMasterId)
        : services;

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent>
                <form onSubmit={onSubmit} className="flex h-full flex-col">
                    <DrawerHeader className="border-b border-[var(--color-line)] dark:border-[var(--color-cal-border)]">
                        <DrawerTitle className="text-[var(--color-ink)]">
                            Новая запись
                        </DrawerTitle>
                        <DrawerDescription className="text-[var(--color-graphite)]">
                            Выберите клиента, услугу и время
                        </DrawerDescription>
                    </DrawerHeader>

                    <DrawerBody className="px-[22px] py-[22px]">
                        <div className="space-y-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Клиент *
                                </label>
                                <ClientCombobox
                                    clients={clients}
                                    value={form.data.client_id}
                                    onChange={(id) => form.setData('client_id', id)}
                                    onClientCreated={onClientCreated}
                                />
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
                                    <SelectContent className="rounded-xl border-[var(--color-line)] bg-white shadow-sm dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)]">
                                        {visibleServices.length === 0 ? (
                                            <SelectItem value="" disabled>
                                                Нет доступных услуг
                                            </SelectItem>
                                        ) : (
                                            visibleServices.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)} className="rounded-lg focus:bg-[var(--color-surface-hover)] focus:text-[var(--color-ink)]">
                                                    {s.title} — {s.duration_minutes} мин, {s.price.toLocaleString('ru-RU')} ₽
                                                </SelectItem>
                                            ))
                                        )}
                                    </SelectContent>
                                </Select>
                                {form.errors.service_id && (
                                    <p className="mt-1 text-xs text-red-500">{form.errors.service_id}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                        Дата *
                                    </label>
                                    <IrsiDatePicker
                                        value={form.data.date}
                                        onChange={(v) => form.setData('date', v)}
                                        min={dateToKey(new Date())}
                                    />
                                    {form.errors.date && (
                                        <p className="mt-1 text-xs text-red-500">{form.errors.date}</p>
                                    )}
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                        Время *
                                    </label>
                                    <IrsiTimeSelect
                                        value={form.data.time}
                                        onChange={(v) => form.setData('time', v)}
                                        options={timeOptions}
                                    />
                                    {form.errors.time && (
                                        <p className="mt-1 text-xs text-red-500">{form.errors.time}</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </DrawerBody>

                    <DrawerFooter className="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.client_id || !form.data.service_id || !form.data.date || !form.data.time}
                            className="flex-1 rounded-xl bg-[var(--color-orange)] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                        >
                            {form.processing ? 'Создание...' : 'Создать запись'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            className="rounded-xl sm:flex-none"
                        >
                            Отмена
                        </Button>
                    </DrawerFooter>
                </form>
            </DrawerContent>
        </Drawer>
    );
}
