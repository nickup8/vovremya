import { useState, useEffect, useRef, useMemo } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { echo } from '@/echo-config';
import Cropper from 'react-easy-crop';
import {
    Send,
    MessageCircle,
    Pencil,
    Copy,
    Check,
    Lock,
} from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PhoneInput } from '@/components/PhoneInput';
import { stripPhoneMask } from '@/lib/phone';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
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
    reminder_hours_before_final: number;
    autofill_enabled: boolean;
    has_slot_autofill: boolean;
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface PageProps {
    profile: Profile;
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

/* ═══════════════ Main Settings Page ═══════════════ */

export default function SettingsPage() {
    const {
        profile: rawProfile,
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
        reminder_hours_before_final: 3,
    };
    const userName = auth?.user?.name || 'Мастер';
    const initials = getInitials(userName);
    const [avatarImageSrc, setAvatarImageSrc] = useState('');
    const [avatarCropOpen, setAvatarCropOpen] = useState(false);
    const [isCopied, setIsCopied] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const VALID_TABS = ['profile', 'notifications', 'booking'] as const;
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
            .listen('.UserChannelsUpdated', () => {
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
        cancellation_deadline_hours: profile.cancellation_deadline_hours?.toString() || '',
        autofill_enabled: profile.autofill_enabled,
    });

    // ── Notifications autosave state ──
    const [notifState, setNotifState] = useState({
        telegram_notifications: profile.telegram_notifications,
        max_notifications: profile.max_notifications,
        reminder_hours_before_final: profile.reminder_hours_before_final,
    });
    const [saveStatus, setSaveStatus] = useState<'idle' | 'saving' | 'error'>('idle');
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const savingRef = useRef(false);
    const pendingRef = useRef(false);
    const notifStateRef = useRef(notifState);
    notifStateRef.current = notifState;

    const flushSave = () => {
        if (savingRef.current) {
            pendingRef.current = true;
            return;
        }
        savingRef.current = true;
        setSaveStatus('saving');
        router.put('/admin/settings', notifStateRef.current, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                savingRef.current = false;
                if (pendingRef.current) {
                    pendingRef.current = false;
                    flushSave();
                } else {
                    setSaveStatus('idle');
                }
            },
            onError: () => {
                savingRef.current = false;
                pendingRef.current = false;
                setSaveStatus('error');
                toast.error('Не удалось сохранить настройки уведомлений');
            },
        });
    };

    const scheduleSave = () => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(flushSave, 400);
    };

    const setNotifField = <K extends keyof typeof notifState>(key: K, value: typeof notifState[K]) => {
        setNotifState((prev) => {
            const next = { ...prev, [key]: value };
            notifStateRef.current = next;
            return next;
        });
        scheduleSave();
    };

    // Sync from profile props (realtime reload) without triggering autosave
    useEffect(() => {
        setNotifState({
            telegram_notifications: profile.telegram_notifications,
            max_notifications: profile.max_notifications,
            reminder_hours_before_final: profile.reminder_hours_before_final,
        });
    }, [profile.telegram_notifications, profile.max_notifications, profile.reminder_hours_before_final]);

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
                            {VALID_TABS.map((tab) => {
                                const labels: Record<TabValue, string> = {
                                    profile: 'Профиль',
                                    notifications: 'Уведомления',
                                    booking: 'Запись',
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
                                                Ссылка для онлайн-записи
                                            </label>
                                            <div className="flex h-[42px] items-center overflow-hidden rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] focus-within:ring-2 focus-within:ring-[var(--color-orange)] focus-within:ring-offset-0">
                                                <span className="shrink-0 select-none pl-3 pr-0.5 text-[13px] text-[var(--color-graphite)]">
                                                    irsi-app.ru/book/
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
                                                    className="h-full min-w-0 flex-1 bg-transparent pl-0 pr-1 text-[13px] text-[var(--color-ink)] outline-none placeholder:text-[var(--color-graphite)]"
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
                                                <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus:ring-2 focus:ring-[var(--color-orange)] focus:ring-offset-0">
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

                            {/* ═══ Tab: Notifications ═══ */}
                            <TabsContent value="notifications">
                            <div className="space-y-0">

                            {/* ═══ Уведомления мастеру ═══ */}
                            <div className="py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Уведомления мастеру
                                    </div>
                                    <div>
                                        {/* Telegram */}
                                        <div className="flex min-h-[64px] items-center gap-3">
                                            <div className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[var(--color-warm)]">
                                                <Send className="size-4 text-[var(--color-graphite)]" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                                    Telegram Bot
                                                </p>
                                                <p className="text-[12px] text-[var(--color-graphite)]">
                                                    {profile.telegram_chat_id
                                                        ? 'Новые записи и сервисные уведомления'
                                                        : 'Сначала подключите Telegram'}
                                                </p>
                                            </div>
                                            <Switch
                                                checked={notifState.telegram_notifications}
                                                disabled={!profile.telegram_chat_id}
                                                onCheckedChange={(checked) => {
                                                    if (!profile.telegram_chat_id) return;
                                                    setNotifField('telegram_notifications', checked);
                                                }}
                                                className="h-6 w-10 data-[state=checked]:bg-[var(--color-orange)] [&>span]:size-5 [&>span]:data-[state=checked]:translate-x-4"
                                            />
                                        </div>
                                        {!profile.telegram_chat_id && (
                                            <div className="flex items-center gap-3 py-2.5 pl-12">
                                                <span className="text-[12px] text-[var(--color-graphite)]">
                                                    Telegram не подключен
                                                </span>
                                                <a
                                                    href={profile.telegram_link_url || `https://t.me/${profile.telegram_bot_name}?start=${profile.telegram_auth_token}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="ml-auto inline-flex h-7 items-center gap-1.5 rounded-[8px] bg-[var(--color-orange)] px-3 text-[11px] font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)]"
                                                >
                                                    <Send className="size-3" />
                                                    Подключить
                                                </a>
                                            </div>
                                        )}

                                        {/* Divider */}
                                        <div className="border-t border-[var(--color-line)]" />

                                        {/* Max */}
                                        <div className="flex min-h-[64px] items-center gap-3">
                                            <div className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[var(--color-warm)]">
                                                <MessageCircle className="size-4 text-[var(--color-graphite)]" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                                    Max Messenger
                                                </p>
                                                <p className="text-[12px] text-[var(--color-graphite)]">
                                                    {profile.max_id
                                                        ? 'Новые записи и сервисные уведомления'
                                                        : 'Сначала подключите MAX'}
                                                </p>
                                            </div>
                                            <Switch
                                                checked={notifState.max_notifications}
                                                disabled={!profile.max_id}
                                                onCheckedChange={(checked) => {
                                                    if (!profile.max_id) return;
                                                    setNotifField('max_notifications', checked);
                                                }}
                                                className="h-6 w-10 data-[state=checked]:bg-[var(--color-orange)] [&>span]:size-5 [&>span]:data-[state=checked]:translate-x-4"
                                            />
                                        </div>
                                        {!profile.max_id && profile.max_link_url && (
                                            <div className="flex items-center gap-3 py-2.5 pl-12">
                                                <span className="text-[12px] text-[var(--color-graphite)]">
                                                    MAX не подключен
                                                </span>
                                                <a
                                                    href={profile.max_link_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="ml-auto inline-flex h-7 items-center gap-1.5 rounded-[8px] bg-[var(--color-ink)] px-3 text-[11px] font-semibold text-white transition-colors hover:opacity-90"
                                                >
                                                    <MessageCircle className="size-3" />
                                                    Подключить
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Уведомления клиентам ═══ */}
                            <div className="border-t border-[var(--color-line)] py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Уведомления клиентам
                                    </div>
                                    <div className="flex min-h-[64px] items-center gap-4">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                                Финальное напоминание
                                            </p>
                                            <p className="text-[12px] text-[var(--color-graphite)]">
                                                Если срок записи позволяет, за 24 часа до визита клиент дополнительно получит уведомление с подтверждением записи.
                                            </p>
                                        </div>
                                        <Select
                                            value={String(notifState.reminder_hours_before_final)}
                                            onValueChange={(value) => setNotifField('reminder_hours_before_final', Number(value))}
                                        >
                                            <SelectTrigger className="h-[36px] w-[180px] shrink-0 rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px] focus:ring-2 focus:ring-[var(--color-orange)] focus:ring-offset-0">
                                                <SelectValue />
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
                                </div>
                            </div>

                            {/* ═══ Autosave Status ═══ */}
                            <div className="flex justify-end border-t border-[var(--color-line)] pt-6">
                                <div className="flex items-center gap-2 text-[12px]">
                                    {saveStatus === 'idle' && (
                                        <>
                                            <span className="size-1.5 rounded-full bg-[var(--color-paid)]" />
                                            <span className="text-[var(--color-graphite)]">Сохраняется автоматически</span>
                                        </>
                                    )}
                                    {saveStatus === 'saving' && (
                                        <>
                                            <span className="size-1.5 animate-pulse rounded-full bg-[var(--color-graphite)]" />
                                            <span className="text-[var(--color-graphite)]">Сохранение…</span>
                                        </>
                                    )}
                                    {saveStatus === 'error' && (
                                        <>
                                            <span className="size-1.5 rounded-full bg-[var(--color-red)]" />
                                            <span className="text-[var(--color-red)]">Не удалось сохранить</span>
                                        </>
                                    )}
                                </div>
                            </div>
                            </div>
                            </TabsContent>

                            {/* ═══ Tab: Запись ═══ */}
                            <TabsContent value="booking">
                            <div className="space-y-0">

                            {/* ═══ Section: Правила записи ═══ */}
                            <div className="py-6">
                                <div className="grid gap-6 min-[720px]:grid-cols-[220px_1fr]">
                                    <div className="text-[13px] font-semibold text-[var(--color-graphite)]">
                                        Правила записи
                                    </div>
                                    <form
                                        onSubmit={(e) => {
 e.preventDefault(); bookingFlowForm.put('/admin/settings', { preserveScroll: true, onSuccess: () => toast.success('Настройки записи сохранены') }); 
}}
                                    >
                                        <div className="space-y-0">
                                            {/* Cancellation */}
                                            <div className="flex min-h-[64px] items-center gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                                        Онлайн-отмена клиентом
                                                    </p>
                                                    <p className="text-[12px] text-[var(--color-graphite)]">
                                                        Не позднее чем за
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        max={168}
                                                        placeholder="—"
                                                        className="h-10 w-[72px] rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-center text-[13px] focus-visible:ring-2 focus-visible:ring-[var(--color-orange)] focus-visible:ring-offset-0"
                                                        value={bookingFlowForm.data.cancellation_deadline_hours}
                                                        onChange={(e) => bookingFlowForm.setData('cancellation_deadline_hours', e.target.value)}
                                                    />
                                                    <span className="text-[13px] text-[var(--color-graphite)]">
                                                        часов до начала
                                                    </span>
                                                </div>
                                            </div>
                                            {bookingFlowForm.errors.cancellation_deadline_hours && (
                                                <p className="text-[11px] text-[var(--color-red)]">
                                                    {bookingFlowForm.errors.cancellation_deadline_hours}
                                                </p>
                                            )}

                                            {/* Divider */}
                                            <div className="border-t border-[var(--color-line)]" />

                                            {/* AutoFill */}
                                            <div className="flex min-h-[64px] items-center justify-between gap-4">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                                        Хочу раньше
                                                    </p>
                                                    <p className="text-[12px] text-[var(--color-graphite)]">
                                                        Если освободится подходящее время, ИРСИ предложит его клиентам, которые попросили сообщить о более раннем окне.
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0">
                                                    {!profile.has_slot_autofill && (
                                                        <span className="text-[11px] font-medium text-[var(--color-graphite)]">Профи</span>
                                                    )}
                                                    <Switch
                                                        checked={bookingFlowForm.data.autofill_enabled}
                                                        onCheckedChange={(checked) => bookingFlowForm.setData('autofill_enabled', checked)}
                                                        disabled={!profile.has_slot_autofill}
                                                        className="h-6 w-10 data-[state=checked]:bg-[var(--color-orange)] [&>span]:size-5 [&>span]:data-[state=checked]:translate-x-4"
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        {/* Save Area */}
                                        <div className="flex justify-end gap-2 border-t border-[var(--color-line)] pt-4">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="h-10 rounded-[10px] border-[var(--color-line)] px-4 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                                                onClick={() => bookingFlowForm.reset()}
                                            >
                                                Отмена
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={bookingFlowForm.processing}
                                                className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                                            >
                                                {bookingFlowForm.processing
                                                    ? 'Сохранение…'
                                                    : 'Сохранить настройки'}
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            </div>
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
