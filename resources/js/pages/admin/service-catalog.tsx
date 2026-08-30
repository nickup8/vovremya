import { useState, useEffect, useCallback, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    PlusIcon,
    EllipsisHorizontalIcon,
    PencilSquareIcon,
    TrashIcon,
    Squares2X2Icon,
} from '@heroicons/react/24/outline';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel,
} from '@/components/ui/alert-dialog';
import AdminLayout from '@/layouts/AdminLayout';

/* ═══════════════ Types ═══════════════ */

interface CatalogItem {
    id: string;
    title: string;
    category: string | null;
    base_price: number;
    base_duration: number;
    is_active: boolean;
}

interface PageProps {
    catalog: CatalogItem[];
    auth?: { user?: { name?: string;[key: string]: unknown } };
    flash?: { success?: string; error?: string };
    errors?: Record<string, string>;
}

type DrawerMode = 'create' | 'edit';
type FormErrors = Partial<Record<'title' | 'base_price' | 'base_duration', string>>;

/* ═══════════════ Helpers ═══════════════ */

function formatPrice(value: number): string {
    return Number(value).toLocaleString('ru-RU') + ' ₽';
}

function formatMeta(item: CatalogItem): string {
    const duration = `${item.base_duration} мин`;
    return item.category ? `${duration} · ${item.category}` : duration;
}

/* ═══════════════ Service Row ═══════════════ */

function ServiceRow({
    item,
    onEdit,
    onDelete,
}: {
    item: CatalogItem;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!menuOpen) return;
        const handler = () => setMenuOpen(false);
        const timer = setTimeout(() => document.addEventListener('click', handler), 0);
        return () => { clearTimeout(timer); document.removeEventListener('click', handler); };
    }, [menuOpen]);

    return (
        <div className="grid min-h-[68px] grid-cols-[minmax(0,1fr)_auto_40px] items-center gap-3 border-t border-[var(--color-line-soft)] py-3 first:border-t-0 md:gap-[18px]">
            {/* Name + meta */}
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="truncate text-sm font-semibold text-[var(--color-ink)]">{item.title}</p>
                    {!item.is_active && (
                        <span className="inline-flex h-[22px] shrink-0 items-center rounded-full border border-[var(--color-line)] bg-[var(--color-warm)] px-2 text-[11px] font-semibold text-[var(--color-graphite)]">
                            Отключена
                        </span>
                    )}
                </div>
                <p className="mt-1 truncate text-xs text-[var(--color-graphite)]">{formatMeta(item)}</p>
            </div>

            {/* Price */}
            <div className="whitespace-nowrap text-right text-sm font-bold tabular-nums text-[var(--color-ink)]">
                {formatPrice(item.base_price)}
            </div>

            {/* Actions */}
            <div className="relative flex justify-end" ref={menuRef}>
                <button
                    onClick={(e) => { e.stopPropagation(); setMenuOpen(!menuOpen); }}
                    className={`inline-flex size-9 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] ${menuOpen ? 'bg-[var(--color-surface-hover)]' : ''}`}
                    aria-label="Действия с услугой"
                    aria-expanded={menuOpen}
                >
                    <EllipsisHorizontalIcon className="size-5" />
                </button>
                {menuOpen && (
                    <div
                        className="absolute right-0 top-[42px] z-40 w-[190px] rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-1.5 shadow-[0_8px_24px_rgba(0,0,0,0.16)]"
                        onClick={(e) => e.stopPropagation()}
                        role="menu"
                    >
                        <button
                            onClick={() => { setMenuOpen(false); onEdit(); }}
                            className="flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2.5 text-[12.5px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                            role="menuitem"
                        >
                            <PencilSquareIcon className="size-[17px] text-[var(--color-graphite)]" />
                            Редактировать
                        </button>
                        <button
                            onClick={() => { setMenuOpen(false); onDelete(); }}
                            className="flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2.5 text-[12.5px] font-medium text-[var(--color-red)] transition-colors hover:bg-[var(--color-red-bg)]"
                            role="menuitem"
                        >
                            <TrashIcon className="size-[17px]" />
                            Удалить
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ═══════════════ Main Page ═══════════════ */

export default function ServiceCatalogPage() {
    const { catalog, auth, flash } = usePage<PageProps>().props;

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [drawerMode, setDrawerMode] = useState<DrawerMode>('create');
    const [editingItem, setEditingItem] = useState<CatalogItem | null>(null);

    const [title, setTitle] = useState('');
    const [category, setCategory] = useState('');
    const [basePrice, setBasePrice] = useState('');
    const [baseDuration, setBaseDuration] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [formErrors, setFormErrors] = useState<FormErrors>({});
    const [processing, setProcessing] = useState(false);

    const [deleteTarget, setDeleteTarget] = useState<CatalogItem | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    const openCreate = useCallback(() => {
        setEditingItem(null);
        setTitle('');
        setCategory('');
        setBasePrice('');
        setBaseDuration('');
        setIsActive(true);
        setFormErrors({});
        setDrawerMode('create');
        setDrawerOpen(true);
    }, []);

    const openEdit = useCallback((item: CatalogItem) => {
        setEditingItem(item);
        setTitle(item.title);
        setCategory(item.category ?? '');
        setBasePrice(item.base_price.toString());
        setBaseDuration(item.base_duration.toString());
        setIsActive(item.is_active);
        setFormErrors({});
        setDrawerMode('edit');
        setDrawerOpen(true);
    }, []);

    const closeDrawer = useCallback(() => {
        setDrawerOpen(false);
        setTimeout(() => {
            setEditingItem(null);
            setFormErrors({});
        }, 200);
    }, []);

    function handleSubmit(e?: React.FormEvent) {
        e?.preventDefault();
        if (processing) return;
        setProcessing(true);
        setFormErrors({});

        const payload = {
            title,
            category: category.trim() || null,
            base_price: Number(basePrice),
            base_duration: Number(baseDuration),
            is_active: isActive,
        };

        const opts = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                setFormErrors(errors);
                setProcessing(false);
            },
            onFinish: () => setProcessing(false),
        };

        if (drawerMode === 'edit' && editingItem) {
            router.put(`/admin/catalog/${editingItem.id}`, payload, {
                ...opts,
                onSuccess: () => { toast.success('Услуга обновлена'); closeDrawer(); },
            });
        } else {
            router.post('/admin/catalog', payload, {
                ...opts,
                onSuccess: () => { toast.success('Услуга добавлена'); closeDrawer(); },
            });
        }
    }

    function confirmDelete() {
        if (!deleteTarget || deleting) return;
        setDeleting(true);
        const title = deleteTarget.title;
        router.delete(`/admin/catalog/${deleteTarget.id}`, {
            preserveScroll: true,
            onSuccess: () => { toast.success(`Услуга «${title}» удалена`); },
            onError: () => { toast.error('Не удалось удалить услугу'); },
            onFinish: () => { setDeleting(false); setDeleteTarget(null); },
        });
    }

    const submitDisabled = !title.trim() || !basePrice.trim() || !baseDuration.trim() || processing;

    return (
        <>
            <Head title="Услуги — Вовремя" />

            <AdminLayout
                title="Услуги"
                auth={auth}
                hideNewAppointment
                fullBleed
                headerActions={
                    <Button
                        onClick={openCreate}
                        className="h-10 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                    >
                        <PlusIcon className="size-4" />
                        <span className="hidden lg:inline">Добавить услугу</span>
                    </Button>
                }
            >
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="mx-auto w-full max-w-[1320px]">
                        <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)]">
                            <div className="flex items-center justify-between gap-4 px-5 pb-3.5 pt-5 md:px-[22px]">
                                <div className="min-w-0">
                                    <h2 className="text-[18px] font-bold text-[var(--color-ink)]">Каталог услуг</h2>
                                    <p className="mt-1 text-xs text-[var(--color-graphite)]">Все услуги, доступные для записи</p>
                                </div>
                            </div>

                            <div className="px-5 pb-5 md:px-[22px] md:pb-[22px]">
                                {catalog.length === 0 ? (
                                    <div className="flex min-h-[200px] flex-col items-center justify-center rounded-[14px] border border-dashed border-[var(--color-line)] px-6 text-center">
                                        <div className="mb-3 flex size-12 items-center justify-center rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)]">
                                            <Squares2X2Icon className="size-[22px] text-[var(--color-graphite)]" />
                                        </div>
                                        <p className="text-[15px] font-semibold text-[var(--color-ink)]">Услуг пока нет</p>
                                        <p className="mt-1 text-xs text-[var(--color-graphite)]">Добавьте первую услугу, доступную для записи</p>
                                        <Button
                                            onClick={openCreate}
                                            className="mt-4 h-10 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                                        >
                                            <PlusIcon className="size-4" />
                                            Добавить услугу
                                        </Button>
                                    </div>
                                ) : (
                                    <div>
                                        {catalog.map((item: CatalogItem) => (
                                            <ServiceRow
                                                key={item.id}
                                                item={item}
                                                onEdit={() => openEdit(item)}
                                                onDelete={() => setDeleteTarget(item)}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>
                    </div>
                </div>
            </AdminLayout>

            {/* ─── Create / Edit Drawer ─── */}
            <Drawer open={drawerOpen} onOpenChange={(open) => { if (!open) closeDrawer(); }}>
                <DrawerContent className="sm:max-w-[480px]">
                    <DrawerHeader>
                        <DrawerTitle>{drawerMode === 'edit' ? 'Редактирование услуги' : 'Новая услуга'}</DrawerTitle>
                    </DrawerHeader>
                    <DrawerBody>
                        <form id="serviceForm" onSubmit={handleSubmit} className="space-y-5">
                            <div className="grid gap-[7px]">
                                <label className="text-[12px] font-semibold text-[var(--color-ink)]">Название услуги</label>
                                <Input
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="Маникюр + покрытие"
                                    className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                    autoFocus
                                />
                                {formErrors.title && <p className="text-xs text-[var(--color-red)]">{formErrors.title}</p>}
                            </div>

                            <div className="grid gap-[7px]">
                                <label className="text-[12px] font-semibold text-[var(--color-ink)]">Категория</label>
                                <Input
                                    value={category}
                                    onChange={(e) => setCategory(e.target.value)}
                                    placeholder="Ногтевой сервис, Парикмахерская..."
                                    className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-[14px] sm:grid-cols-2">
                                <div className="grid gap-[7px]">
                                    <label className="text-[12px] font-semibold text-[var(--color-ink)]">Базовая цена (₽)</label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="numeric"
                                        value={basePrice}
                                        onChange={(e) => setBasePrice(e.target.value)}
                                        placeholder="1500"
                                        className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                    />
                                    {formErrors.base_price && <p className="text-xs text-[var(--color-red)]">{formErrors.base_price}</p>}
                                </div>
                                <div className="grid gap-[7px]">
                                    <label className="text-[12px] font-semibold text-[var(--color-ink)]">Длительность (мин)</label>
                                    <Input
                                        type="number"
                                        min="1"
                                        inputMode="numeric"
                                        value={baseDuration}
                                        onChange={(e) => setBaseDuration(e.target.value)}
                                        placeholder="60"
                                        className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                    />
                                    {formErrors.base_duration && <p className="text-xs text-[var(--color-red)]">{formErrors.base_duration}</p>}
                                </div>
                            </div>

                            <div className="flex min-h-[52px] items-center justify-between gap-4 border-t border-[var(--color-line-soft)] pt-4">
                                <span className="text-[13px] font-semibold text-[var(--color-ink)]">Активна</span>
                                <Switch
                                    checked={isActive}
                                    onCheckedChange={setIsActive}
                                    className="data-[state=checked]:bg-[var(--color-orange)]"
                                />
                            </div>
                        </form>
                    </DrawerBody>
                    <DrawerFooter>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={closeDrawer}
                                className="h-11 text-sm font-semibold"
                            >
                                Отмена
                            </Button>
                            <Button
                                type="submit"
                                form="serviceForm"
                                disabled={submitDisabled}
                                className="h-11 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                            >
                                {drawerMode === 'edit' ? 'Сохранить' : 'Добавить'}
                            </Button>
                        </div>
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>

            {/* ─── Delete confirmation ─── */}
            <AlertDialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Удалить услугу?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleteTarget && `Услуга «${deleteTarget.title}» будет удалена из каталога. Это действие нельзя отменить.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>Отмена</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(e) => { e.preventDefault(); confirmDelete(); }}
                            disabled={deleting}
                            className="bg-red-600 text-white hover:bg-red-700"
                        >
                            Удалить
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
