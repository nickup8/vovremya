import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout';

/* ═══════════════ Types ═══════════════ */

interface CatalogItem {
    id: string;
    title: string;
    category: string | null;
    base_price: number;
    base_duration: number;
    is_active: boolean;
    master_services_count: number;
}

interface PageProps {
    catalog: CatalogItem[];
    auth?: { user?: { name?: string; [key: string]: unknown } };
    flash?: { success?: string; error?: string };
    errors?: Record<string, string>;
}

/* ═══════════════ Modal ═══════════════ */

function CatalogModal({
    open,
    onClose,
    item,
}: {
    open: boolean;
    onClose: () => void;
    item: CatalogItem | null;
}) {
    const { errors: pageErrors } = usePage<PageProps>().props;
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState('');
    const [basePrice, setBasePrice] = useState('');
    const [baseDuration, setBaseDuration] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open && item) {
            setTitle(item.title);
            setCategory(item.category ?? '');
            setBasePrice(item.base_price.toString());
            setBaseDuration(item.base_duration.toString());
            setIsActive(item.is_active);
        } else if (open) {
            setTitle('');
            setCategory('');
            setBasePrice('');
            setBaseDuration('');
            setIsActive(true);
        }
    }, [open, item?.id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = {
            title,
            category: category || null,
            base_price: Number(basePrice),
            base_duration: Number(baseDuration),
            is_active: isActive,
        };

        if (item) {
            router.put(`/admin/catalog/${item.id}`, payload, {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    toast.success('Услуга обновлена');
                    onClose();
                },
            });
        } else {
            router.post('/admin/catalog', payload, {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    toast.success('Услуга добавлена в каталог');
                    onClose();
                },
            });
        }
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {item ? 'Редактировать услугу' : 'Новая услуга в каталоге'}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Название услуги
                        </label>
                        <Input
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="Маникюр + покрытие"
                            className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                        />
                        {pageErrors?.title && (
                            <p className="mt-1 text-xs text-red-500">{pageErrors.title}</p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Категория
                        </label>
                        <Input
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            placeholder="Ногтевой сервис, Парикмахерская..."
                            className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Базовая цена (₽)
                            </label>
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={basePrice}
                                onChange={(e) => setBasePrice(e.target.value)}
                                placeholder="1500"
                                className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                            />
                            {pageErrors?.base_price && (
                                <p className="mt-1 text-xs text-red-500">{pageErrors.base_price}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Длительность (мин)
                            </label>
                            <Input
                                type="number"
                                min="1"
                                value={baseDuration}
                                onChange={(e) => setBaseDuration(e.target.value)}
                                placeholder="60"
                                className="bg-slate-50 placeholder:text-zinc-400 dark:bg-zinc-800 dark:placeholder:text-zinc-600"
                            />
                            {pageErrors?.base_duration && (
                                <p className="mt-1 text-xs text-red-500">{pageErrors.base_duration}</p>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Switch
                            checked={isActive}
                            onCheckedChange={setIsActive}
                        />
                        <label className="text-sm font-medium text-slate-700 dark:text-zinc-300">
                            Активна
                        </label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="bg-blue-600 text-white hover:bg-blue-700"
                        >
                            {processing ? 'Сохранение...' : item ? 'Сохранить' : 'Добавить'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ═══════════════ Page ═══════════════ */

export default function ServiceCatalogPage() {
    const { catalog, auth, flash } = usePage<PageProps>().props;
    const [modalOpen, setModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<CatalogItem | null>(null);

    useEffect(() => {
        if (flash?.success) {
toast.success(flash.success);
}

        if (flash?.error) {
toast.error(flash.error);
}
    }, [flash?.success, flash?.error]);

    const handleAdd = () => {
        setEditingItem(null);
        setModalOpen(true);
    };

    const handleEdit = (item: CatalogItem) => {
        setEditingItem(item);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingItem(null);
    };

    const handleDelete = (item: CatalogItem) => {
        if (confirm(`Удалить услугу «${item.title}» из каталога?`)) {
            router.delete(`/admin/catalog/${item.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Каталог услуг — Вовремя" />

            <AdminLayout title="Каталог услуг студии" auth={auth}>
                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                                Услуги студии
                            </h3>
                            <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
                                Базовый справочник услуг. Мастера наследуют цены и длительность.
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            className="bg-blue-600 text-white hover:bg-blue-700"
                            onClick={handleAdd}
                        >
                            <Plus className="size-3.5" />
                            Добавить
                        </Button>
                    </div>

                    {catalog.length === 0 ? (
                        <p className="py-8 text-center text-sm text-slate-400 dark:text-zinc-500">
                            Каталог пуст. Нажмите «Добавить», чтобы создать первую услугу.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {catalog.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center justify-between rounded-lg bg-slate-50 p-3 transition-colors hover:bg-slate-100 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
                                >
                                    <div className="flex items-center gap-4">
                                        <div>
                                            <p className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                                                {item.title}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-zinc-400">
                                                {item.base_duration} мин
                                                {item.category && <> · {item.category}</>}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(`/admin/catalog/${item.id}/toggle-active`, {}, { preserveScroll: true })
                                            }
                                            className={`inline-flex cursor-pointer items-center rounded-full px-2 py-0.5 text-xs font-medium transition-colors hover:opacity-80 ${
                                                item.is_active
                                                    ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-400'
                                            }`}
                                        >
                                            {item.is_active ? 'Активна' : 'Скрыта'}
                                        </button>
                                        {item.master_services_count > 0 && (
                                            <span className="text-xs text-slate-400 dark:text-zinc-500">
                                                · {item.master_services_count}{' '}
                                                {item.master_services_count === 1 ? 'мастер' : 'мастеров'}
                                            </span>
                                        )}

                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-bold text-slate-900 dark:text-zinc-100">
                                            {Number(item.base_price).toLocaleString('ru-RU')} ₽
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => handleEdit(item)}
                                            className="rounded p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:text-zinc-500 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                        >
                                            <Pencil className="size-3.5" />
                                        </button>
                                        {item.master_services_count > 0 ? (
                                            <span
                                                className="rounded p-1.5 text-slate-300 dark:text-zinc-600"
                                                title="Используется мастерами, удалить нельзя"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => handleDelete(item)}
                                                className="rounded p-1.5 text-slate-400 hover:bg-red-100 hover:text-red-600 dark:text-zinc-500 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </AdminLayout>

            <CatalogModal
                open={modalOpen}
                onClose={handleCloseModal}
                item={editingItem}
            />
        </>
    );
}
