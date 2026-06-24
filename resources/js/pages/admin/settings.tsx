import { useState, useEffect, useRef } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import Cropper from 'react-easy-crop';
import {
    Menu, Send, MessageCircle, Pencil, Plus, Trash2, X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Sidebar from '@/components/admin/Sidebar';

/* ═══════════════ Types ═══════════════ */

interface Profile {
    name: string;
    phone: string | null;
    master_slug: string | null;
    specialty: string | null;
    address: string | null;
    avatar_url: string | null;
    telegram_id: string | null;
    soft_deposit: boolean;
    deposit_timeout: number;
    deposit_percent: number;
    telegram_notifications: boolean;
    max_notifications: boolean;
}

interface Service {
    id: number;
    title: string;
    duration_minutes: number;
    price: number;
}

interface AuthUser {
    name: string;
    [key: string]: unknown;
}

interface PageProps {
    profile: Profile;
    services: Service[];
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Toggle Component ═══════════════ */

function Toggle({
    enabled,
    onToggle,
}: {
    enabled: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                enabled ? 'bg-blue-600' : 'bg-slate-300 dark:bg-zinc-600'
            }`}
        >
            <span
                className={`inline-block size-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-in-out ${
                    enabled ? 'translate-x-5' : 'translate-x-0.5'
                }`}
            />
        </button>
    );
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
    const [croppedAreaPixels, setCroppedAreaPixels] = useState<{ x: number; y: number; width: number; height: number } | null>(null);
    const [uploading, setUploading] = useState(false);

    const getCroppedImg = (src: string, pixelCrop: { x: number; y: number; width: number; height: number }): Promise<File> => {
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

                canvas.toBlob((blob) => {
                    if (!blob) {
                        reject(new Error('Canvas пустой'));
                        return;
                    }
                    const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                    resolve(file);
                }, 'image/jpeg', 0.95);
            };

            image.onerror = () => reject(new Error('Не удалось загрузить изображение'));
        });
    };

    const handleApplyCrop = async () => {
        if (!imageSrc || !croppedAreaPixels) return;

        try {
            setUploading(true);

            const croppedFile = await getCroppedImg(imageSrc, croppedAreaPixels);

            const formData = new FormData();
            formData.append('avatar', croppedFile);

            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

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
                alert('Ошибка при загрузке: ' + (errorData.message || 'Неизвестная ошибка'));
            }
        } catch (error) {
            console.error(error);
            alert('Ошибка обработки: ' + (error as Error).message);
        } finally {
            setUploading(false);
        }
    };

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center">
            <div className="fixed inset-0 bg-black/60" onClick={onClose} />
            <div className="relative z-10 mx-4 w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold text-slate-900 dark:text-zinc-100">
                        Редактирование фото профиля
                    </h3>
                    <button
                        onClick={onClose}
                        disabled={uploading}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-zinc-500 dark:hover:bg-zinc-800"
                    >
                        <X className="size-5" />
                    </button>
                </div>

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
                        onCropComplete={(_croppedArea, croppedAreaPixels) => setCroppedAreaPixels(croppedAreaPixels)}
                    />
                </div>

                {/* Zoom Slider */}
                <div className="mt-4 flex items-center gap-3">
                    <span className="text-xs text-slate-500 dark:text-zinc-400">−</span>
                    <input
                        type="range"
                        min={1}
                        max={3}
                        step={0.1}
                        value={zoom}
                        onChange={(e) => setZoom(Number(e.target.value))}
                        className="h-1.5 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200 dark:bg-zinc-700 [&::-webkit-slider-thumb]:size-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-600 [&::-webkit-slider-thumb]:shadow-sm"
                    />
                    <span className="text-xs text-slate-500 dark:text-zinc-400">+</span>
                </div>

                {/* Action Buttons */}
                <div className="mt-5 flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={uploading}
                        className="rounded-lg"
                    >
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        onClick={handleApplyCrop}
                        disabled={uploading || !croppedAreaPixels}
                        className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700"
                    >
                        {uploading ? 'Загрузка...' : 'Применить'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Service Modal ═══════════════ */

function ServiceModal({
    open,
    onClose,
    service,
}: {
    open: boolean;
    onClose: () => void;
    service: Service | null;
}) {
    const form = useForm({
        title: '',
        duration_minutes: '',
        price: '',
    });

    useEffect(() => {
        if (open && service) {
            form.setData({
                title: service.title,
                duration_minutes: service.duration_minutes.toString(),
                price: service.price.toString(),
            });
        } else if (open) {
            form.reset();
        }
    }, [open, service?.id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (service) {
            form.put(`/admin/services/${service.id}`, {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        } else {
            form.post('/admin/services', {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onClose();
                },
            });
        }
    };

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="fixed inset-0 bg-black/50" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold text-slate-900 dark:text-zinc-100">
                        {service ? 'Редактировать услугу' : 'Новая услуга'}
                    </h3>
                    <button
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-zinc-500 dark:hover:bg-zinc-800"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Название услуги
                        </label>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Маникюр + покрытие"
                            className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                        />
                        {form.errors.title && (
                            <p className="mt-1 text-xs text-red-500">{form.errors.title}</p>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Длительность (мин)
                            </label>
                            <Input
                                type="number"
                                min="1"
                                value={form.data.duration_minutes}
                                onChange={(e) => form.setData('duration_minutes', e.target.value)}
                                placeholder="60"
                                className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                            />
                            {form.errors.duration_minutes && (
                                <p className="mt-1 text-xs text-red-500">{form.errors.duration_minutes}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Стоимость (₽)
                            </label>
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.price}
                                onChange={(e) => form.setData('price', e.target.value)}
                                placeholder="1500"
                                className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                            />
                            {form.errors.price && (
                                <p className="mt-1 text-xs text-red-500">{form.errors.price}</p>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            className="rounded-lg"
                        >
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700"
                        >
                            {form.processing ? 'Сохранение...' : service ? 'Сохранить' : 'Добавить'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/* ═══════════════ Main Settings Page ═══════════════ */

export default function SettingsPage() {
    const { profile, services, auth } = usePage<PageProps>().props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [serviceModalOpen, setServiceModalOpen] = useState(false);
    const [editingService, setEditingService] = useState<Service | null>(null);
    const [avatarImageSrc, setAvatarImageSrc] = useState('');
    const [avatarCropOpen, setAvatarCropOpen] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const userName = auth?.user?.name || 'Мастер';
    const initials = userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const form = useForm({
        name: profile.name,
        phone: profile.phone || '',
        specialty: profile.specialty || '',
        address: profile.address || '',
        master_slug: profile.master_slug || '',
        telegram_id: profile.telegram_id || '',
        soft_deposit: profile.soft_deposit,
        deposit_timeout: profile.deposit_timeout?.toString() || '15',
        deposit_percent: profile.deposit_percent?.toString() || '30',
        telegram_notifications: profile.telegram_notifications,
        max_notifications: profile.max_notifications,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/admin/settings', {
            preserveScroll: true,
        });
    };

    const handleAvatarChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

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

    const handleAddService = () => {
        setEditingService(null);
        setServiceModalOpen(true);
    };

    const handleEditService = (svc: Service) => {
        setEditingService(svc);
        setServiceModalOpen(true);
    };

    const handleCloseModal = () => {
        setServiceModalOpen(false);
        setEditingService(null);
    };

    const handleDeleteService = (service: Service) => {
        if (confirm('Удалить услугу «' + service.title + '»?')) {
            router.delete(`/admin/services/${service.id}`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title="Настройки профиля — Вовремя" />

            <div className="flex min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-zinc-900 dark:text-zinc-50">
                <Sidebar mobileOpen={mobileMenuOpen} onMobileClose={() => setMobileMenuOpen(false)} />

                <div className="flex min-w-0 flex-1 flex-col">
                    {/* Header */}
                    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/80">
                        <div className="flex items-center gap-4">
                            <button
                                onClick={() => setMobileMenuOpen(true)}
                                className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800 lg:hidden"
                            >
                                <Menu className="size-5 text-slate-700 dark:text-zinc-300" />
                            </button>
                            <h1 className="text-lg font-semibold text-slate-900 dark:text-zinc-100 md:text-xl">
                                Настройки профиля
                            </h1>
                        </div>
                        <div className="flex items-center gap-4">
                            <div className="text-right hidden sm:block">
                                <p className="text-sm font-medium text-slate-700 dark:text-zinc-300">{userName}</p>
                                <p className="text-xs text-slate-400 dark:text-zinc-500">Тариф: Профи</p>
                            </div>
                            <div className="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-bold text-white">
                                {initials}
                            </div>
                        </div>
                    </header>

                    {/* Content Area */}
                    <main className="flex-1 overflow-y-auto p-4 md:p-6">
                        <form onSubmit={handleSubmit} className="max-w-4xl space-y-6">
                            {/* Success Message */}
                            {form.recentlySuccessful && (
                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    Настройки успешно сохранены
                                </div>
                            )}

                            {/* ═══ Card 1: Master Profile ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Профиль мастера
                                </h3>

                                {/* Avatar */}
                                <div className="mb-6 flex items-center gap-4">
                                    <div className="relative">
                                        {profile.avatar_url ? (
                                            <img
                                                src={profile.avatar_url}
                                                alt={userName}
                                                className="size-16 rounded-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex size-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xl font-bold text-white">
                                                {initials}
                                            </div>
                                        )}
                                    </div>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept="image/*"
                                        className="hidden"
                                        onChange={handleAvatarChange}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="rounded-lg"
                                        onClick={() => fileInputRef.current?.click()}
                                    >
                                        <Pencil className="size-3.5" />
                                        Изменить фото
                                    </Button>
                                </div>

                                {/* Fields Grid */}
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Имя / Название студии
                                        </label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder="ИП Климин П. А."
                                            className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                                        />
                                        {form.errors.name && (
                                            <p className="mt-1 text-xs text-red-500">{form.errors.name}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Телефон
                                        </label>
                                        <Input
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                            placeholder="+7 (911) 123-45-67"
                                            className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                                        />
                                        {form.errors.phone && (
                                            <p className="mt-1 text-xs text-red-500">{form.errors.phone}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Slug профиля
                                        </label>
                                        <div className="flex items-center">
                                            <span className="shrink-0 rounded-l-lg border border-r-0 border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                                domain.com/a/
                                            </span>
                                            <Input
                                                value={form.data.master_slug}
                                                onChange={(e) => form.setData('master_slug', e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''))}
                                                placeholder="nails_studio"
                                                className="rounded-l-none bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                                            />
                                        </div>
                                        {form.errors.master_slug && (
                                            <p className="mt-1 text-xs text-red-500">{form.errors.master_slug}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Telegram ID
                                        </label>
                                        <Input
                                            value={form.data.telegram_id}
                                            onChange={(e) => form.setData('telegram_id', e.target.value)}
                                            placeholder="555666777"
                                            className="bg-slate-50 font-mono placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                                        />
                                        {form.errors.telegram_id && (
                                            <p className="mt-1 text-xs text-red-500">{form.errors.telegram_id}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Card 2: Soft Deposit ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                                            Мягкая предоплата
                                        </h3>
                                        <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
                                            Защита от No-Show без эквайринга
                                        </p>
                                    </div>
                                    <Toggle
                                        enabled={form.data.soft_deposit}
                                        onToggle={() => form.setData('soft_deposit', !form.data.soft_deposit)}
                                    />
                                </div>

                                {/* Conditional content */}
                                <div className={`overflow-hidden transition-all duration-300 ${form.data.soft_deposit ? 'mt-4 max-h-[500px] opacity-100' : 'max-h-0 opacity-0'}`}>
                                    {/* Warning Banner */}
                                    <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-4 text-sm text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/20 dark:text-amber-200">
                                        При включении опции новая запись переходит в статус «Ожидает подтверждения». Клиенту дается 15 минут на перевод по вашим реквизитам.
                                    </div>

                                    {/* Fields */}
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                Таймаут (минуты)
                                            </label>
                                            <Input
                                                type="number"
                                                min="1"
                                                value={form.data.deposit_timeout}
                                                onChange={(e) => form.setData('deposit_timeout', e.target.value)}
                                                className="bg-slate-50 dark:bg-zinc-800"
                                            />
                                            {form.errors.deposit_timeout && (
                                                <p className="mt-1 text-xs text-red-500">{form.errors.deposit_timeout}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                                Процент предоплаты
                                            </label>
                                            <Input
                                                type="number"
                                                min="1"
                                                max="100"
                                                value={form.data.deposit_percent}
                                                onChange={(e) => form.setData('deposit_percent', e.target.value)}
                                                className="bg-slate-50 dark:bg-zinc-800"
                                            />
                                            {form.errors.deposit_percent && (
                                                <p className="mt-1 text-xs text-red-500">{form.errors.deposit_percent}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Card 3: Notification Channels ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-zinc-100">
                                    Каналы уведомлений
                                </h3>
                                <div className="space-y-3">
                                    {/* Telegram */}
                                    <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3 dark:bg-zinc-800/50">
                                        <div className="flex items-center gap-3">
                                            <div className="flex size-9 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                                                <Send className="size-4 text-blue-600 dark:text-blue-400" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                    Telegram Bot
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                    PUSH-уведомления о новых записях
                                                </p>
                                            </div>
                                        </div>
                                        <Toggle
                                            enabled={form.data.telegram_notifications}
                                            onToggle={() => form.setData('telegram_notifications', !form.data.telegram_notifications)}
                                        />
                                    </div>

                                    {/* Max */}
                                    <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3 dark:bg-zinc-800/50">
                                        <div className="flex items-center gap-3">
                                            <div className="flex size-9 items-center justify-center rounded-lg bg-slate-200 dark:bg-zinc-700">
                                                <MessageCircle className="size-4 text-slate-600 dark:text-zinc-300" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                                    Max Messenger
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                    Уведомления в экосистеме Max
                                                </p>
                                            </div>
                                        </div>
                                        <Toggle
                                            enabled={form.data.max_notifications}
                                            onToggle={() => form.setData('max_notifications', !form.data.max_notifications)}
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* ═══ Card 4: Services Price List ═══ */}
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="mb-4 flex items-center justify-between">
                                    <div>
                                        <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                                            Прайс-лист услуг
                                        </h3>
                                        <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
                                            Управление услугами и ценами
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="bg-blue-600 text-white hover:bg-blue-700"
                                        onClick={handleAddService}
                                    >
                                        <Plus className="size-3.5" />
                                        Добавить
                                    </Button>
                                </div>
                                <div className="space-y-2">
                                    {services.length === 0 ? (
                                        <p className="py-8 text-center text-sm text-slate-400 dark:text-zinc-500">
                                            Пока нет услуг. Нажмите «Добавить», чтобы создать первую.
                                        </p>
                                    ) : (
                                        services.map((service) => (
                                            <div
                                                key={service.id}
                                                className="flex items-center justify-between rounded-lg bg-slate-50 p-3 transition-colors hover:bg-slate-100 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
                                            >
                                                <div>
                                                    <p className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                                                        {service.title}
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                        {service.duration_minutes} мин
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <span className="text-sm font-bold text-slate-900 dark:text-zinc-100">
                                                        {Number(service.price).toLocaleString('ru-RU')} ₽
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleEditService(service)}
                                                        className="rounded p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:text-zinc-500 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                                    >
                                                        <Pencil className="size-3.5" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDeleteService(service)}
                                                        className="rounded p-1.5 text-slate-400 hover:bg-red-100 hover:text-red-600 dark:text-zinc-500 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            {/* ═══ Action Buttons ═══ */}
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
                                    disabled={form.processing}
                                    className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700"
                                >
                                    {form.processing ? 'Сохранение...' : 'Сохранить изменения'}
                                </Button>
                            </div>
                        </form>
                    </main>
                </div>
            </div>

            {/* Modals */}
            <ServiceModal
                key={editingService?.id ?? 'new'}
                open={serviceModalOpen}
                onClose={handleCloseModal}
                service={editingService}
            />

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
