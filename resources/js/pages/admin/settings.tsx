import { useState, useEffect, useRef, useMemo } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { echo } from '@/echo-config';
import Cropper from 'react-easy-crop';
import {
    Send,
    MessageCircle,
    Pencil,
    Plus,
    Trash2,
    Clock,
    Copy,
    Check,
    Lock,
} from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PhoneInput } from '@/components/PhoneInput';
import { stripPhoneMask } from '@/lib/phone';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import AdminLayout from '@/layouts/AdminLayout';
import { getInitials } from '@/lib/utils';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';

/* ═══════════════ Types ═══════════════ */

interface Profile {
    id: string;
    name: string;
    phone: string | null;
    master_slug: string | null;
    specialty: string | null;
    address: string | null;
    avatar_url: string | null;
    telegram_id: string | null;
    telegram_chat_id: string | null;
    telegram_auth_token: string | null;
    telegram_bot_name: string | null;
    telegram_link_url: string | null;
    max_id: string | null;
    max_link_url: string | null;
    soft_deposit: boolean;
    deposit_timeout: number;
    deposit_percent: number;
    slot_interval: number;
    cancellation_deadline_hours: number | null;
    telegram_notifications: boolean;
    max_notifications: boolean;
    timezone: string;
    timezone_confirmed: boolean;
    booking_flow_type: string;
    custom_prepayment_message: string | null;
    reminder_hours_before_final: number;
    autofill_enabled: boolean;
    has_slot_autofill: boolean;
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface WorkingHour {
    id: string;
    day_of_week: number;
    start_time: string | null;
    end_time: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    is_working: boolean;
}

interface BlockedTime {
    id: string;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

interface PageProps {
    profile: Profile;
    workingHours: WorkingHour[];
    blockedTimes: BlockedTime[];
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Avatar Crop Modal ═══════════════ */

function AvatarCropModal({
    open,
    onClose,
    imageSrc,
}: {
    open: boolean;
    onClose: () => void;
    imageSrc: string;
}) {
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [croppedAreaPixels, setCroppedAreaPixels] = useState<{
        x: number;
        y: number;
        width: number;
        height: number;
    } | null>(null);
    const [uploading, setUploading] = useState(false);

    const getCroppedImg = (
        src: string,
        pixelCrop: { x: number; y: number; width: number; height: number },
    ): Promise<File> => {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.src = src;
            image.crossOrigin = 'anonymous';

            image.onload = () => {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                if (!ctx) {
                    reject(new Error('Canvas context error'));

                    return;
                }

                canvas.width = pixelCrop.width;
                canvas.height = pixelCrop.height;

                ctx.drawImage(
                    image,
                    pixelCrop.x,
                    pixelCrop.y,
                    pixelCrop.width,
                    pixelCrop.height,
                    0,
                    0,
                    pixelCrop.width,
                    pixelCrop.height,
                );

                canvas.toBlob(
                    (blob) => {
                        if (!blob) {
                            reject(new Error('Canvas пустой'));

                            return;
                        }

                        const file = new File([blob], 'avatar.jpg', {
                            type: 'image/jpeg',
                        });
                        resolve(file);
                    },
                    'image/jpeg',
                    0.95,
                );
            };

            image.onerror = () =>
                reject(new Error('Не удалось загрузить изображение'));
        });
    };

    const handleApplyCrop = async () => {
        if (!imageSrc || !croppedAreaPixels) {
return;
}

        try {
            setUploading(true);

            const croppedFile = await getCroppedImg(
                imageSrc,
                croppedAreaPixels,
            );

            const formData = new FormData();
            formData.append('avatar', croppedFile);

            const csrfToken =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement
                )?.content || '';

            const response = await fetch('/admin/settings/avatar', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.ok) {
                onClose();
                window.location.reload();
            } else {
                const errorData = await response.json().catch(() => ({}));
                alert(
                    'Ошибка при загрузке: ' +
                        (errorData.message || 'Неизвестная ошибка'),
                );
            }
        } catch (error) {
            console.error(error);
            alert('Ошибка обработки: ' + (error as Error).message);
        } finally {
            setUploading(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Редактирование фото профиля</DialogTitle>
                </DialogHeader>

                {/* Crop Area */}
                <div className="relative h-72 w-full overflow-hidden rounded-xl bg-slate-100 dark:bg-zinc-800">
                    <Cropper
                        image={imageSrc}
                        crop={crop}
                        zoom={zoom}
                        aspect={1}
                        cropShape="round"
                        onCropChange={setCrop}
                        onZoomChange={setZoom}
                        onCropComplete={(_croppedArea, croppedAreaPixels) =>
                            setCroppedAreaPixels(croppedAreaPixels)
                        }
                    />
                </div>

                {/* Zoom Slider */}
                <div className="mt-4 flex items-center gap-3">
                    <span className="text-xs text-slate-500 dark:text-zinc-400">
                        −
                    </span>
                    <input
                        type="range"
                        min={1}
                        max={3}
                        step={0.1}
                        value={zoom}
                        onChange={(e) => setZoom(Number(e.target.value))}
                        className="h-1.5 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200 dark:bg-zinc-700 [&::-webkit-slider-thumb]:size-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-600 [&::-webkit-slider-thumb]:shadow-sm"
                    />
                    <span className="text-xs text-slate-500 dark:text-zinc-400">
                        +
                    </span>
                </div>

                {/* Action Buttons */}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={uploading}
                    >
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        onClick={handleApplyCrop}
                        disabled={uploading || !croppedAreaPixels}
                        className="bg-blue-600 text-white hover:bg-blue-700"
                    >
                        {uploading ? 'Загрузка...' : 'Применить'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ═══════════════ Working Hours Card ═══════════════ */

const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
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

function WorkingHoursCard({
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
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                        График работы
                    </h3>
                    {isDirty && (
                        <span className="text-xs font-medium text-amber-600 dark:text-amber-400">
                            ● Несохранённые изменения
                        </span>
                    )}
                </div>
            </div>

            {/* Slot Interval Selector */}
            <div className="mb-4 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">
                    Интервал записи:
                </span>
                <div className="flex gap-1">
                    {SLOT_INTERVALS.map((interval) => (
                        <button
                            key={interval}
                            type="button"
                            onClick={() => setSlotInterval(interval)}
                            className={`rounded-md px-3 py-1 text-xs font-medium transition-colors ${
                                slotInterval === interval
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-200 text-slate-600 hover:bg-slate-300 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600'
                            }`}
                        >
                            {interval} мин
                        </button>
                    ))}
                </div>
            </div>

            <div className="space-y-2">
                {localHours.map((hour) => (
                    <div
                        key={hour.day_of_week}
                        className={`rounded-lg p-3 transition-colors ${
                            hour.is_working
                                ? 'bg-slate-50 dark:bg-zinc-800/50'
                                : 'bg-slate-100/50 dark:bg-zinc-800/20'
                        }`}
                    >
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={() => toggleDay(hour.day_of_week)}
                                className={`w-10 shrink-0 rounded-md px-2 py-1 text-xs font-bold transition-colors ${
                                    hour.is_working
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-slate-200 text-slate-500 dark:bg-zinc-700 dark:text-zinc-400'
                                }`}
                            >
                                {DAY_NAMES[hour.day_of_week]}
                            </button>

                            {hour.is_working ? (
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="time"
                                        value={hour.start_time ?? ''}
                                        onChange={(e) =>
                                            updateTime(
                                                hour.day_of_week,
                                                'start_time',
                                                e.target.value,
                                            )
                                        }
                                        className="h-8 w-28 text-xs dark:bg-zinc-800"
                                    />
                                    <span className="text-xs text-slate-400 dark:text-zinc-500">
                                        —
                                    </span>
                                    <Input
                                        type="time"
                                        value={hour.end_time ?? ''}
                                        onChange={(e) =>
                                            updateTime(
                                                hour.day_of_week,
                                                'end_time',
                                                e.target.value,
                                            )
                                        }
                                        className="h-8 w-28 text-xs dark:bg-zinc-800"
                                    />
                                </div>
                            ) : (
                                <span className="text-xs text-slate-400 dark:text-zinc-500">
                                    Выходной
                                </span>
                            )}
                        </div>

                        {hour.is_working && (
                            <div className="mt-2 flex items-center gap-3 pl-[52px]">
                                <span className="text-[11px] font-medium text-slate-500 dark:text-zinc-400">
                                    Обед:
                                </span>
                                <Input
                                    type="time"
                                    value={hour.break_start_time || ''}
                                    onChange={(e) =>
                                        updateTime(
                                            hour.day_of_week,
                                            'break_start_time',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Начало"
                                    className="h-7 w-24 text-[11px] dark:bg-zinc-800"
                                />
                                <span className="text-[11px] text-slate-400 dark:text-zinc-500">
                                    —
                                </span>
                                <Input
                                    type="time"
                                    value={hour.break_end_time || ''}
                                    onChange={(e) =>
                                        updateTime(
                                            hour.day_of_week,
                                            'break_end_time',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Конец"
                                    className="h-7 w-24 text-[11px] dark:bg-zinc-800"
                                />
                            </div>
                        )}
                    </div>
                ))}
            </div>

            <div className="mt-6 flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="rounded-lg"
                    disabled={!isDirty}
                >
                    Отмена
                </Button>
                <Button
                    type="button"
                    onClick={handleSave}
                    disabled={!isDirty || processing}
                    className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Clock className="size-3.5" />
                    Сохранить график
                </Button>
            </div>
        </div>
    );
}

/* ═══════════════ Blocked Times Card ═══════════════ */

const BLOCKED_REASONS = [
    'Отпуск',
    'Больничный',
    'Обед',
    'Личное время',
    'Другое',
];

function BlockedTimesCard({ masterId }: { masterId?: string }) {
    const { blockedTimes: rawBlockedTimes } = usePage<PageProps>().props;
    const blockedTimes = rawBlockedTimes || [];
    const [dialogOpen, setDialogOpen] = useState(false);
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [reason, setReason] = useState('Другое');

    function handleAdd() {
        if (!startDate || !endDate) {
return;
}

        const payload: Record<string, unknown> = {
            start_datetime: startDate,
            end_datetime: endDate,
            reason,
        };

        if (masterId) {
            payload.master_id = masterId;
        }

        router.post(
            '/admin/blocked-times',
            payload,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDialogOpen(false);
                    setStartDate('');
                    setEndDate('');
                    setReason('Другое');
                },
            },
        );
    }

    function handleDelete(id: string) {
        if (confirm('Удалить блокировку?')) {
            router.delete(`/admin/blocked-times/${id}`, {
                preserveScroll: true,
            });
        }
    }

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                        Недоступное время
                    </h3>
                    <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
                        Блокировки отпуска, обедов и прочего
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    className="bg-blue-600 text-white hover:bg-blue-700"
                    onClick={() => setDialogOpen(true)}
                >
                    <Plus className="size-3.5" />
                    Добавить
                </Button>
            </div>

            {blockedTimes.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-400 dark:text-zinc-500">
                    Нет активных блокировок
                </p>
            ) : (
                <div className="space-y-2">
                    {blockedTimes.map((bt) => (
                        <div
                            key={bt.id}
                            className="flex items-center justify-between rounded-lg bg-slate-50 p-3 dark:bg-zinc-800/50"
                        >
                            <div>
                                <p className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                                    {bt.reason}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-zinc-400">
                                    {new Date(
                                        bt.start_datetime,
                                    ).toLocaleDateString('ru-RU')}{' '}
                                    —{' '}
                                    {new Date(
                                        bt.end_datetime,
                                    ).toLocaleDateString('ru-RU')}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => handleDelete(bt.id)}
                                className="rounded p-1.5 text-slate-400 hover:bg-red-100 hover:text-red-600 dark:text-zinc-500 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Новая блокировка</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Причина
                            </label>
                            <Select value={reason} onValueChange={setReason}>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {BLOCKED_REASONS.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {r}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    С
                                </label>
                                <input
                                    type="datetime-local"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    По
                                </label>
                                <input
                                    type="datetime-local"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialogOpen(false)}
                            >
                                Отмена
                            </Button>
                            <Button
                                type="button"
                                onClick={handleAdd}
                                disabled={!startDate || !endDate}
                                className="bg-blue-600 text-white hover:bg-blue-700"
                            >
                                Добавить
                            </Button>
                        </DialogFooter>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ═══════════════ Main Settings Page ═══════════════ */

export default function SettingsPage() {
    const {
        profile: rawProfile,
        workingHours: rawWorkingHours,
        auth,
    } = usePage<PageProps>().props;
    const profile = rawProfile || {
        id: '',
        name: '',
        phone: null,
        master_slug: null,
        specialty: null,
        address: null,
        avatar_url: null,
        telegram_id: null,
        telegram_chat_id: null,
        telegram_auth_token: null,
        telegram_bot_name: null,
        telegram_link_url: null,
        max_id: null,
        max_link_url: null,
        deposit_timeout: 15,
        deposit_percent: 30,
        slot_interval: 30,
        telegram_notifications: false,
        max_notifications: false,
        timezone: 'Europe/Moscow',
        timezone_confirmed: false,
        booking_flow_type: 'free_verification',
        custom_prepayment_message: null,
        reminder_hours_before_final: 3,
    };
    const workingHours = rawWorkingHours || [];
    const userName = auth?.user?.name || 'Мастер';
    const initials = getInitials(userName);
    const [avatarImageSrc, setAvatarImageSrc] = useState('');
    const [avatarCropOpen, setAvatarCropOpen] = useState(false);
    const [isCopied, setIsCopied] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const VALID_TABS = ['profile', 'booking', 'notifications', 'schedule'] as const;
    type TabValue = (typeof VALID_TABS)[number];

    const getTabFromUrl = (): TabValue => {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');

        return VALID_TABS.includes(tab as TabValue) ? (tab as TabValue) : 'profile';
    };

    const [activeTab, setActiveTab] = useState<TabValue>(getTabFromUrl);

    const handleTabChange = (value: string) => {
        setActiveTab(value as TabValue);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', value);
        window.history.replaceState({}, '', url.toString());
    };

    const profileForm = useForm({
        name: profile.name,
        phone: profile.phone || '',
        specialty: profile.specialty || '',
        address: profile.address || '',
        master_slug: profile.master_slug || '',
        telegram_id: profile.telegram_id || '',
        max_id: profile.max_id || '',
        telegram_notifications: profile.telegram_notifications,
        max_notifications: profile.max_notifications,
    });

    useEffect(() => {
        if (!profile?.id) {
return;
}

        const channelName = `App.Models.User.${profile.id}`;
        const channel = echo< 'reverb' >().private(channelName)
            .listen('.UserChannelsUpdated', (e: { user: Profile }) => {
                profileForm.setData({
                    telegram_notifications: e.user.telegram_notifications,
                    max_notifications: e.user.max_notifications,
                    telegram_id: e.user.telegram_id || '',
                    max_id: e.user.max_id || '',
                });
                router.reload({
                    only: ['profile'],
                    preserveScroll: true,
                });
            });

        return () => {
            channel.stopListening('.UserChannelsUpdated');
            echo< 'reverb' >().leave(channelName);
        };
    }, [profile?.id]);

    const bookingFlowForm = useForm({
        booking_flow_type: profile.booking_flow_type,
        custom_prepayment_message: profile.custom_prepayment_message || '',
        reminder_hours_before_final: profile.reminder_hours_before_final,
        deposit_timeout: profile.deposit_timeout?.toString() || '15',
        deposit_percent: profile.deposit_percent?.toString() || '30',
        cancellation_deadline_hours: profile.cancellation_deadline_hours?.toString() || '',
        autofill_enabled: profile.autofill_enabled,
    });

    const handleAvatarChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
return;
}

        if (file.size > 5 * 1024 * 1024) {
            alert('Файл слишком большой. Максимальный размер — 5 МБ.');

            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            setAvatarImageSrc(reader.result as string);
            setAvatarCropOpen(true);
        };
        reader.readAsDataURL(file);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    return (
        <>
            <Head title="Настройки профиля — Вовремя" />

            <AdminLayout title="Настройки профиля" auth={auth} hideNewAppointment fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="max-w-[1280px]">
                        <TimezoneConfirmBanner
                            confirmed={profile.timezone_confirmed}
                        />

                        {/* ═══ Underline Tabs ═══ */}
                        <div className="mb-6 flex items-center gap-6 overflow-x-auto border-b border-[var(--color-line)] scrollbar-none">
                            {(['profile', 'booking', 'notifications', 'schedule'] as const).map((tab) => {
                                const labels: Record<string, string> = {
                                    profile: 'Профиль',
                                    booking: 'Запись и оплата',
                                    notifications: 'Уведомления',
                                    schedule: 'Расписание',
                                };
                                const isActive = activeTab === tab;

                                return (
                                    <button
                                        key={tab}
                                        type="button"
                                        onClick={() => handleTabChange(tab)}
                                        className={`relative h-[43px] shrink-0 px-0.5 text-[13.5px] font-semibold transition-colors ${
                                            isActive
                                                ? 'text-[var(--color-ink)] after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:rounded-t-[3px] after:bg-[var(--color-orange)]'
                                                : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                                        }`}
                                    >
                                        {labels[tab]}
                                    </button>
                                );
                            })}
                        </div>

                        <Tabs value={activeTab} onValueChange={handleTabChange} className="w-full">

                            {/* ═══ Tab: Profile ═══ */}
                            <TabsContent value="profile">
                            <form
                                onSubmit={(e) => {
 e.preventDefault(); profileForm.put('/admin/settings', { preserveScroll: true, onSuccess: () => toast.success('Профиль сохранён') }); 
}}
                                className="space-y-0"
                            >

                            {/* ═══ Profile Header ═══ */}
                            <div className="mb-8 flex flex-wrap items-center gap-5">
                                <Avatar className="size-24 rounded-[16px]">
                                    <AvatarImage src={profile.avatar_url ?? undefined} alt={userName} className="object-cover" />
                                    <AvatarFallback className="bg-[var(--color-avatar)] text-2xl font-bold text-[var(--color-graphite)]">
                                        {initials}
                                    </AvatarFallback>
                                </Avatar>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={handleAvatarChange}
                                />
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-[18px] font-bold leading-[22px] tracking-[-.02em] text-[var(--color-ink)]">
                                        {userName}
                                    </h2>
                                    <p className="mt-0.5 text-[13px] text-[var(--color-graphite)]">
                                        Профиль мастера
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-9 rounded-[10px] border-[var(--color-line)] px-3.5 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                                        onClick={() => fileInputRef.current?.click()}
                                    >
                                        <Pencil className="size-3.5" />
                                        Изменить фото
                                    </Button>
                                    {profile.avatar_url && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.delete('/admin/settings/avatar', {
                                                    preserveScroll: true,
                                                    onSuccess: () => toast.success('Фото удалено'),
                                                })
                                            }
                                            className="inline-flex h-9 items-center gap-1.5 rounded-[10px] px-3.5 text-[13px] font-semibold text-[var(--color-red)] transition-colors hover:bg-[var(--color-red-bg)]"
                                        >
                                            Удалить
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* ═══ Основная информация ═══ */}
                            <div className="border-t border-[var(--color-line)] py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Основная информация
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 min-[520px]:grid-cols-2">
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Имя
                                            </label>
                                            <Input
                                                value={profileForm.data.name}
                                                onChange={(e) =>
                                                    profileForm.setData('name', e.target.value)
                                                }
                                                placeholder="ИП Климин П. А."
                                                className="h-[42px] rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] placeholder:text-[var(--color-graphite)] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                            />
                                            {profileForm.errors.name && (
                                                <p className="mt-1 text-xs text-[var(--color-red)]">
                                                    {profileForm.errors.name}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Телефон
                                            </label>
                                            <PhoneInput
                                                value={profileForm.data.phone ?? ''}
                                                onChange={(val) =>
                                                    profileForm.setData('phone', stripPhoneMask(val))
                                                }
                                                placeholder="+7 (911) 123-45-67"
                                                className="h-[42px] rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] placeholder:text-[var(--color-graphite)] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                            />
                                            {profileForm.errors.phone && (
                                                <p className="mt-1 text-xs text-[var(--color-red)]">
                                                    {profileForm.errors.phone}
                                                </p>
                                            )}
                                        </div>
                                        <div className="min-[520px]:col-span-2">
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Адрес
                                            </label>
                                            <Input
                                                value={profileForm.data.address}
                                                onChange={(e) =>
                                                    profileForm.setData('address', e.target.value)
                                                }
                                                placeholder="г. Москва, ул. Примерная, д. 1"
                                                className="h-[42px] rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] placeholder:text-[var(--color-graphite)] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                            />
                                            {profileForm.errors.address && (
                                                <p className="mt-1 text-xs text-[var(--color-red)]">
                                                    {profileForm.errors.address}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Публичный профиль ═══ */}
                            <div className="border-t border-[var(--color-line)] py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Публичный профиль
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 min-[520px]:grid-cols-2">
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Slug профиля
                                            </label>
                                            <div className="flex h-[42px] items-center overflow-hidden rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] focus-within:ring-2 focus-within:ring-[var(--color-orange)] focus-within:ring-offset-0">
                                                <span className="shrink-0 select-none border-r border-[var(--color-line)] bg-[var(--color-line-soft)] px-3 text-[13px] text-[var(--color-graphite)]">
                                                    {typeof window !== 'undefined' ? window.location.origin : ''}/book/
                                                </span>
                                                <input
                                                    type="text"
                                                    value={profileForm.data.master_slug}
                                                    onChange={(e) =>
                                                        profileForm.setData(
                                                            'master_slug',
                                                            e.target.value
                                                                .toLowerCase()
                                                                .replace(/[^a-z0-9_-]/g, ''),
                                                        )
                                                    }
                                                    placeholder="nails_studio"
                                                    className="h-full min-w-0 flex-1 bg-transparent px-3 text-[13px] text-[var(--color-ink)] outline-none placeholder:text-[var(--color-graphite)]"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        const slug = profileForm.data.master_slug;
                                                        if (!slug) return;
                                                        const url = `${window.location.origin}/book/${slug}`;
                                                        navigator.clipboard.writeText(url).then(() => {
                                                            setIsCopied(true);
                                                            toast.success('Ссылка скопирована');
                                                            setTimeout(() => setIsCopied(false), 2000);
                                                        });
                                                    }}
                                                    className="flex shrink-0 items-center justify-center px-3 text-[var(--color-graphite)] transition-colors hover:text-[var(--color-ink)]"
                                                    title="Скопировать ссылку"
                                                >
                                                    {isCopied ? (
                                                        <Check className="size-4 text-[var(--color-paid)]" />
                                                    ) : (
                                                        <Copy className="size-4" />
                                                    )}
                                                </button>
                                            </div>
                                            {profileForm.errors.master_slug && (
                                                <p className="mt-1 text-xs text-[var(--color-red)]">
                                                    {profileForm.errors.master_slug}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Часовой пояс
                                            </label>
                                            <Select
                                                value={profile.timezone}
                                                onValueChange={(value) => {
                                                    router.patch(
                                                        '/admin/settings/timezone',
                                                        { timezone: value },
                                                        {
                                                            preserveScroll: true,
                                                            preserveState: false,
                                                            only: ['profile'],
                                                        },
                                                    );
                                                }}
                                            >
                                                <SelectTrigger className="h-[42px] rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus:ring-2 focus:ring-[var(--color-orange)] focus:ring-offset-0">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="Europe/Kaliningrad">Kaliningrad (UTC+2)</SelectItem>
                                                    <SelectItem value="Europe/Moscow">Moscow (UTC+3)</SelectItem>
                                                    <SelectItem value="Europe/Samara">Samara (UTC+4)</SelectItem>
                                                    <SelectItem value="Asia/Yekaterinburg">Yekaterinburg (UTC+5)</SelectItem>
                                                    <SelectItem value="Asia/Omsk">Omsk (UTC+6)</SelectItem>
                                                    <SelectItem value="Asia/Krasnoyarsk">Krasnoyarsk (UTC+7)</SelectItem>
                                                    <SelectItem value="Asia/Irkutsk">Irkutsk (UTC+8)</SelectItem>
                                                    <SelectItem value="Asia/Yakutsk">Yakutsk (UTC+9)</SelectItem>
                                                    <SelectItem value="Asia/Vladivostok">Vladivostok (UTC+10)</SelectItem>
                                                    <SelectItem value="Asia/Magadan">Magadan (UTC+11)</SelectItem>
                                                    <SelectItem value="Asia/Kamchatka">Kamchatka (UTC+12)</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Интеграции ═══ */}
                            <div className="border-t border-[var(--color-line)] py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Интеграции
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 min-[520px]:grid-cols-2">
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                Telegram ID
                                            </label>
                                            <div className="flex h-[42px] items-center gap-2 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-line-soft)] px-3">
                                                <span className="min-w-0 flex-1 truncate font-mono text-[13px] text-[var(--color-graphite)]">
                                                    {profileForm.data.telegram_id || '—'}
                                                </span>
                                                <Lock className="size-3.5 shrink-0 text-[var(--color-graphite)]" />
                                            </div>
                                        </div>
                                        <div>
                                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                                ID профиля в Max
                                            </label>
                                            <div className="flex h-[42px] items-center gap-2 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-line-soft)] px-3">
                                                <span className="min-w-0 flex-1 truncate font-mono text-[13px] text-[var(--color-graphite)]">
                                                    {profileForm.data.max_id || '—'}
                                                </span>
                                                <Lock className="size-3.5 shrink-0 text-[var(--color-graphite)]" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Save Area ═══ */}
                            <div className="flex justify-end gap-2 border-t border-[var(--color-line)] pt-6">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 rounded-[10px] border-[var(--color-line)] px-4 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={profileForm.processing}
                                    className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                                >
                                    {profileForm.processing
                                        ? 'Сохранение...'
                                        : 'Сохранить профиль'}
                                </Button>
                            </div>
                            </form>
                            </TabsContent>

                            {/* ═══ Tab: Booking & Payment ═══ */}
                            <TabsContent value="booking">
                            <form
                                onSubmit={(e) => {
 e.preventDefault(); bookingFlowForm.put('/admin/settings', { preserveScroll: true, onSuccess: () => toast.success('Настройки записи сохранены') }); 
}}
                                className="space-y-6"
                            >

                            {/* ═══ Card: Режим записи ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-1 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Режим записи
                                </h3>
                                <p className="mb-4 text-sm text-slate-500 dark:text-zinc-400">
                                    Как клиенты записываются к вам
                                </p>

                                <RadioGroup
                                    value={bookingFlowForm.data.booking_flow_type}
                                    onValueChange={(value) => bookingFlowForm.setData('booking_flow_type', value)}
                                    className="space-y-3"
                                >
                                    <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 transition-colors hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50 [&:has([data-state=checked])]:border-blue-600 [&:has([data-state=checked])]:bg-blue-50/50 dark:[&:has([data-state=checked])]:border-blue-500 dark:[&:has([data-state=checked])]:bg-blue-950/20">
                                        <RadioGroupItem value="free_verification" id="flow-free" className="mt-0.5" />
                                        <div>
                                            <p className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                                                Свободная запись
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                Клиент записывается, вы подтверждаете вручную
                                            </p>
                                        </div>
                                    </label>

                                    <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 transition-colors hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50 [&:has([data-state=checked])]:border-blue-600 [&:has([data-state=checked])]:bg-blue-50/50 dark:[&:has([data-state=checked])]:border-blue-500 dark:[&:has([data-state=checked])]:bg-blue-950/20">
                                        <RadioGroupItem value="prepayment_custom" id="flow-prepay" className="mt-0.5" />
                                        <div>
                                            <p className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                                                Предоплата по реквизитам
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                Клиент вносит предоплату и присылает подтверждение
                                            </p>
                                        </div>
                                    </label>
                                </RadioGroup>

                                {bookingFlowForm.data.booking_flow_type === 'prepayment_custom' && (
                                    <div className="mt-4 space-y-4">
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                Реквизиты и инструкция для клиента
                                            </label>
                                            <Textarea
                                                value={bookingFlowForm.data.custom_prepayment_message}
                                                onChange={(e) => bookingFlowForm.setData('custom_prepayment_message', e.target.value)}
                                                placeholder="Например: Переведите 500 ₽ на карту 0000 0000 0000 0000 (Сбербанк) и пришлите скриншот в этот чат"
                                                maxLength={1000}
                                                rows={4}
                                                className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                                            />
                                            <p className="mt-1 text-right text-xs text-slate-400 dark:text-zinc-500">
                                                {bookingFlowForm.data.custom_prepayment_message.length}/1000
                                            </p>
                                            {bookingFlowForm.errors.custom_prepayment_message && (
                                                <p className="mt-1 text-xs text-red-500">
                                                    {bookingFlowForm.errors.custom_prepayment_message}
                                                </p>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                    Процент предоплаты
                                                </label>
                                                <div className="relative">
                                                    <Input
                                                        type="number"
                                                        min="1"
                                                        max="100"
                                                        value={bookingFlowForm.data.deposit_percent}
                                                        onChange={(e) => bookingFlowForm.setData('deposit_percent', Number(e.target.value) || 0)}
                                                        className="bg-slate-50 pr-8 dark:bg-zinc-800"
                                                    />
                                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 dark:text-zinc-500">
                                                        %
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                                                    Сколько процентов от стоимости услуги клиент вносит заранее
                                                </p>
                                                {bookingFlowForm.errors.deposit_percent && (
                                                    <p className="mt-1 text-xs text-red-500">
                                                        {bookingFlowForm.errors.deposit_percent}
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                    Время на оплату
                                                </label>
                                                <div className="relative">
                                                    <Input
                                                        type="number"
                                                        min="5"
                                                        max="1440"
                                                        value={bookingFlowForm.data.deposit_timeout}
                                                        onChange={(e) => bookingFlowForm.setData('deposit_timeout', Number(e.target.value) || 0)}
                                                        className="bg-slate-50 pr-12 dark:bg-zinc-800"
                                                    />
                                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 dark:text-zinc-500">
                                                        мин
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                                                    Сколько минут слот удерживается в ожидании оплаты. После — освобождается автоматически
                                                </p>
                                                {bookingFlowForm.errors.deposit_timeout && (
                                                    <p className="mt-1 text-xs text-red-500">
                                                        {bookingFlowForm.errors.deposit_timeout}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* ═══ Card: Финальное напоминание ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-1 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Финальное напоминание
                                </h3>
                                <p className="mb-4 text-sm text-slate-500 dark:text-zinc-400">
                                    За сколько часов до записи отправить клиенту финальное напоминание
                                </p>

                                <Select
                                    value={String(bookingFlowForm.data.reminder_hours_before_final)}
                                    onValueChange={(value) => bookingFlowForm.setData('reminder_hours_before_final', Number(value))}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Выберите интервал" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="0">Не отправлять</SelectItem>
                                        <SelectItem value="1">За 1 час</SelectItem>
                                        <SelectItem value="2">За 2 часа</SelectItem>
                                        <SelectItem value="3">За 3 часа</SelectItem>
                                        <SelectItem value="12">За 12 часов</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* ═══ Card: Дедлайн отмены онлайн ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-1 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Ограничение отмены онлайн
                                </h3>
                                <p className="mb-4 text-sm text-slate-500 dark:text-zinc-400">
                                    За сколько часов до визита клиент уже не может отменить запись в боте. Пусто — без ограничения.
                                </p>

                                <Input
                                    type="number"
                                    min={1}
                                    max={168}
                                    placeholder="Например, 24"
                                    className="w-full"
                                    value={bookingFlowForm.data.cancellation_deadline_hours}
                                    onChange={(e) =>
                                        bookingFlowForm.setData('cancellation_deadline_hours', e.target.value)
                                    }
                                />
                                {bookingFlowForm.errors.cancellation_deadline_hours && (
                                    <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {bookingFlowForm.errors.cancellation_deadline_hours}
                                    </p>
                                )}
                            </div>

                            {/* ═══ Card: Автозаполнение окон ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 className="mb-1 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                            Автозаполнение окон
                                        </h3>
                                        <p className="text-sm text-slate-500 dark:text-zinc-400">
                                            Если освободится время, ИРСИ сможет предложить его клиентам, которые заранее попросили сообщить о подходящем окне.
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2 shrink-0">
                                        {!profile.has_slot_autofill && (
                                            <span className="text-xs font-medium text-slate-400 dark:text-zinc-500">Профи</span>
                                        )}
                                        <Switch
                                            checked={bookingFlowForm.data.autofill_enabled}
                                            onCheckedChange={(checked) => bookingFlowForm.setData('autofill_enabled', checked)}
                                            disabled={!profile.has_slot_autofill}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-lg"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={bookingFlowForm.processing}
                                    className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700"
                                >
                                    {bookingFlowForm.processing
                                        ? 'Сохранение...'
                                        : 'Сохранить настройки'}
                                </Button>
                            </div>
                            </form>
                            </TabsContent>

                            {/* ═══ Tab: Notifications ═══ */}
                            <TabsContent value="notifications">
                            <form
                                onSubmit={(e) => {
 e.preventDefault(); profileForm.put('/admin/settings', { preserveScroll: true, onSuccess: () => toast.success('Уведомления сохранены') }); 
}}
                                className="space-y-6"
                            >

                            {/* ═══ Card 3: Notification Channels ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Каналы уведомлений
                                </h3>
                                <div className="space-y-3">
                                    {/* Telegram */}
                                    <div className="rounded-lg bg-slate-50 p-3 dark:bg-zinc-800/50">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-9 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                                                    <Send className="size-4 text-blue-600 dark:text-blue-400" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                        Telegram Bot
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                        {profile.telegram_chat_id
                                                            ? 'PUSH-уведомления о новых записях'
                                                            : 'Сначала подключите Telegram'}
                                                    </p>
                                                </div>
                                            </div>
                                            <Switch
                                                checked={profileForm.data.telegram_notifications}
                                                disabled={!profile.telegram_chat_id}
                                                onCheckedChange={(checked) => {
                                                    if (!profile.telegram_chat_id) {
return;
}

                                                    profileForm.setData('telegram_notifications', checked);
                                                }}
                                            />
                                        </div>
                                        {!profile.telegram_chat_id && (
                                            <div className="mt-3 flex items-center gap-3 rounded-md border border-dashed border-slate-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                                                <span className="text-xs text-slate-500 dark:text-zinc-400">
                                                    Telegram не подключен
                                                </span>
                                                <a
                                                    href={profile.telegram_link_url || `https://t.me/${profile.telegram_bot_name}?start=${profile.telegram_auth_token}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="ml-auto inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                                                >
                                                    <Send className="size-3" />
                                                    Подключить
                                                </a>
                                            </div>
                                        )}
                                    </div>

                                    {/* Max */}
                                    <div className="rounded-lg bg-slate-50 p-3 dark:bg-zinc-800/50">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-9 items-center justify-center rounded-lg bg-slate-200 dark:bg-zinc-700">
                                                    <MessageCircle className="size-4 text-slate-600 dark:text-zinc-300" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                        Max Messenger
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                        {profile.max_id
                                                            ? 'Уведомления в экосистеме Max'
                                                            : 'Сначала подключите MAX'}
                                                    </p>
                                                </div>
                                            </div>
                                            <Switch
                                                checked={profileForm.data.max_notifications}
                                                disabled={!profile.max_id}
                                                onCheckedChange={(checked) => {
                                                    if (!profile.max_id) {
return;
}

                                                    profileForm.setData('max_notifications', checked);
                                                }}
                                            />
                                        </div>
                                        {!profile.max_id && profile.max_link_url && (
                                            <div className="mt-3 flex items-center gap-3 rounded-md border border-dashed border-slate-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                                                <span className="text-xs text-slate-500 dark:text-zinc-400">
                                                    MAX не подключен
                                                </span>
                                                <a
                                                    href={profile.max_link_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="ml-auto inline-flex items-center gap-1.5 rounded-md bg-slate-700 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-slate-800"
                                                >
                                                    <MessageCircle className="size-3" />
                                                    Подключить
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-lg"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={profileForm.processing}
                                    className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700"
                                >
                                    {profileForm.processing
                                        ? 'Сохранение...'
                                        : 'Сохранить уведомления'}
                                </Button>
                            </div>
                            </form>
                            </TabsContent>

                            {/* ═══ Tab: Schedule ═══ */}
                            <TabsContent value="schedule">

                            {/* ═══ Card 5: Working Hours ═══ */}
                            <WorkingHoursCard
                                workingHours={workingHours}
                                slotInterval={profile.slot_interval || 30}
                                masterId={profile.id}
                            />

                            {/* ═══ Card 6: Blocked Times ═══ */}
                            <BlockedTimesCard masterId={profile.id} />

                            </TabsContent>
                            </Tabs>
                    </div>
                </div>
            </AdminLayout>

            {/* Modals */}
            <AvatarCropModal
                open={avatarCropOpen}
                onClose={() => {
                    setAvatarCropOpen(false);
                    setAvatarImageSrc('');
                }}
                imageSrc={avatarImageSrc}
            />
        </>
    );
}
