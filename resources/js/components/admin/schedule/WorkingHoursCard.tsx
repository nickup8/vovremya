import { useState, useEffect, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

export interface WorkingHour {
    id: string;
    day_of_week: number;
    start_time: string | null;
    end_time: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    is_working: boolean;
}

const DAY_NAMES = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
const SLOT_INTERVALS = [15, 30, 60];

const uiToCarbon = (uiIndex: number) => (uiIndex + 1) % 7;

function buildHours(workingHours: WorkingHour[]): WorkingHour[] {
    const hours: WorkingHour[] = [];

    for (let i = 0; i < 7; i++) {
        const existing = workingHours.find(
            (h) => h.day_of_week === uiToCarbon(i),
        );
        hours.push(
            existing
                  ? { ...existing, day_of_week: i }
                  : {
                      id: '',
                      day_of_week: i,
                      start_time: '09:00',
                      end_time: '18:00',
                      break_start_time: '13:00',
                      break_end_time: '14:00',
                      is_working: i < 5,
                  },
        );
    }

    return hours;
}

export default function WorkingHoursCard({
    workingHours,
    slotInterval: initialSlotInterval,
    masterId,
}: {
    workingHours: WorkingHour[];
    slotInterval: number;
    masterId?: string;
}) {
    const [localHours, setLocalHours] = useState<WorkingHour[]>(() =>
        buildHours(workingHours),
    );
    const [slotInterval, setSlotInterval] = useState(initialSlotInterval);
    const initialHours = useMemo(
        () => buildHours(workingHours),
        [workingHours],
    );
    const serialize = (hours: WorkingHour[]) =>
        JSON.stringify(
            hours.map((h) => ({
                day_of_week: h.day_of_week,
                is_working: h.is_working,
                start_time: h.start_time,
                end_time: h.end_time,
                break_start_time: h.break_start_time,
                break_end_time: h.break_end_time,
            }))
        );

    const isDirty = useMemo(
        () =>
            slotInterval !== initialSlotInterval ||
            serialize(localHours) !== serialize(initialHours),
        [localHours, slotInterval, initialHours, initialSlotInterval],
    );

    function sanitizeTime(val: unknown): string | null {
        if (val == null) {
return null;
}

        const s = String(val).trim();

        if (s === '' || s === '--:--' || s === '--' || s === ':' || s === '_' || /^[\s\-_:]+$/.test(s)) {
return null;
}

        return s;
    }

    const { data, setData, put, transform, processing } = useForm({
        working_hours: buildHours(workingHours),
        slot_interval: initialSlotInterval,
    });

    transform((currentData: typeof data) => ({
        ...currentData,
        working_hours: currentData.working_hours.map((h: WorkingHour) => {
            const isOff = !h.is_working;

            return {
                ...h,
                day_of_week: uiToCarbon(h.day_of_week),
                start_time: isOff ? null : sanitizeTime(h.start_time),
                end_time: isOff ? null : sanitizeTime(h.end_time),
                break_start_time: isOff ? null : sanitizeTime(h.break_start_time),
                break_end_time: isOff ? null : sanitizeTime(h.break_end_time),
            };
        }),
    }));

    useEffect(() => {
        setData('working_hours', localHours);
        setData('slot_interval', slotInterval);
    }, [localHours, slotInterval]);

    function toggleDay(dayOfWeek: number) {
        setLocalHours((prev) =>
            prev.map((h) => {
                if (h.day_of_week !== dayOfWeek) {
return h;
}

                if (!h.is_working) {
                    return {
                        ...h,
                        is_working: true,
                        start_time: h.start_time ?? '09:00',
                        end_time: h.end_time ?? '18:00',
                    };
                }

                return { ...h, is_working: false };
            }),
        );
    }

    function updateTime(
        dayOfWeek: number,
        field:
            | 'start_time'
            | 'end_time'
            | 'break_start_time'
            | 'break_end_time',
        value: string,
    ) {
        setLocalHours((prev) =>
            prev.map((h) =>
                h.day_of_week === dayOfWeek ? { ...h, [field]: value } : h,
            ),
        );
    }

    function handleSave() {
        const payload = masterId ? { ...data, master_id: masterId } : data;
        put('/admin/working-hours', {
            ...payload,
            preserveScroll: true,
            onSuccess: () => toast.success('График работы сохранён'),
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                toast.error(typeof firstError === 'string' ? firstError : 'Ошибка сохранения графика');
            },
        });
    }

    return (
        <>
            {/* Slot Interval */}
            <div className="flex min-h-[64px] items-center justify-between gap-4">
                <div>
                    <p className="text-[14px] font-semibold text-[var(--color-ink)]">
                        Шаг онлайн-записи
                    </p>
                    <p className="text-[12px] text-[var(--color-graphite)]">
                        Минимальный интервал между слотами
                    </p>
                </div>
                <div className="inline-flex shrink-0 items-center gap-[3px] rounded-[12px] border border-[var(--color-line)] bg-[var(--color-warm)] p-[3px]">
                    {SLOT_INTERVALS.map((interval) => (
                        <button
                            key={interval}
                            type="button"
                            onClick={() => setSlotInterval(interval)}
                            className={`h-8 cursor-pointer rounded-[9px] px-3 text-[12px] font-semibold transition-colors ${
                                slotInterval === interval
                                    ? 'bg-[var(--color-surface-elevated)] text-[var(--color-ink)] shadow-sm'
                                    : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)]'
                            }`}
                        >
                            {interval} мин
                        </button>
                    ))}
                </div>
            </div>

            {/* Divider */}
            <div className="border-t border-[var(--color-line)]" />

            {/* Working Hours Table */}
            <div className="py-4">
                <div className="hidden min-[680px]:grid min-[680px]:grid-cols-[160px_1fr_1fr_auto] items-center gap-x-4 border-b border-[var(--color-line)] pb-2 text-[11px] font-semibold uppercase tracking-[.04em] text-[var(--color-graphite)]/80">
                    <span>День</span>
                    <span>Рабочее время</span>
                    <span>Перерыв</span>
                    <span className="w-[60px] text-right">Вых.</span>
                </div>
                <div>
                    {localHours.map((hour) => (
                        <div
                            key={hour.day_of_week}
                            className={`flex min-h-[58px] flex-wrap items-center gap-x-4 gap-y-2 border-b border-[var(--color-line)] px-0 py-2.5 transition-colors min-[680px]:grid min-[680px]:grid-cols-[160px_1fr_1fr_auto] ${
                                hour.is_working ? '' : 'opacity-50'
                            }`}
                        >
                            <span className={`w-full min-w-[120px] text-[13px] font-semibold min-[680px]:w-auto ${hour.is_working ? 'text-[var(--color-ink)]' : 'text-[var(--color-graphite)]'}`}>
                                {DAY_NAMES[hour.day_of_week]}
                            </span>

                            {hour.is_working ? (
                                <>
                                    <div className="flex items-center gap-1.5">
                                        <Input
                                            type="time"
                                            value={hour.start_time ?? ''}
                                            onChange={(e) => updateTime(hour.day_of_week, 'start_time', e.target.value)}
                                            className="h-10 w-24 rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                        />
                                        <span className="text-[13px] text-[var(--color-graphite)]">—</span>
                                        <Input
                                            type="time"
                                            value={hour.end_time ?? ''}
                                            onChange={(e) => updateTime(hour.day_of_week, 'end_time', e.target.value)}
                                            className="h-10 w-24 rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                        />
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Input
                                            type="time"
                                            value={hour.break_start_time || ''}
                                            onChange={(e) => updateTime(hour.day_of_week, 'break_start_time', e.target.value)}
                                            placeholder="—:—"
                                            className="h-10 w-24 rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                        />
                                        <span className="text-[13px] text-[var(--color-graphite)]">—</span>
                                        <Input
                                            type="time"
                                            value={hour.break_end_time || ''}
                                            onChange={(e) => updateTime(hour.day_of_week, 'break_end_time', e.target.value)}
                                            placeholder="—:—"
                                            className="h-10 w-24 rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                        />
                                    </div>
                                </>
                            ) : (
                                <>
                                    <span className="text-[13px] text-[var(--color-graphite)]">Выходной</span>
                                    <span />
                                </>
                            )}

                            <div className="flex w-[60px] justify-end">
                                <Switch
                                    checked={hour.is_working}
                                    onCheckedChange={() => toggleDay(hour.day_of_week)}
                                    className="h-6 w-10 data-[state=checked]:bg-[var(--color-orange)] [&>span]:size-5 [&>span]:data-[state=checked]:translate-x-4"
                                />
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Save Area */}
            <div className="flex justify-end gap-2 border-t border-[var(--color-line)] pt-4">
                <Button
                    type="button"
                    variant="outline"
                    className="h-10 rounded-[10px] border-[var(--color-line)] px-4 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                    disabled={!isDirty}
                    onClick={() => {
                        setLocalHours(buildHours(workingHours));
                        setSlotInterval(initialSlotInterval);
                    }}
                >
                    Отмена
                </Button>
                <Button
                    type="button"
                    onClick={handleSave}
                    disabled={!isDirty || processing}
                    className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processing ? 'Сохранение…' : 'Сохранить график'}
                </Button>
            </div>
        </>
    );
}
