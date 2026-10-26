import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';

/* ═══════════════ Types ═══════════════ */

interface ReceiptRow {
    id: string;
    status: 'awaiting_manual' | 'attached';
    receipt_url: string | null;
    attached_at: string | null;
    attached_by_user: { id: string; name: string } | null;
    refund_review_required: boolean;
    refund_reviewed_at: string | null;
    created_at: string;
    attempt: {
        id: string;
        amount: number;
        currency: string;
        confirmed_at: string | null;
        provider: string;
        payment_env: string | null;
        origin: string;
        period_start: string | null;
        period_end: string | null;
        tariff_name: string | null;
        workspace_id: string | null;
        owner_name: string | null;
        owner_email: string | null;
    };
}

interface PaginatedReceipts {
    data: ReceiptRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface ReceiptsProps {
    receipts: PaginatedReceipts;
    tab: string;
    canUpdate: boolean;
    flash?: { success?: string; error?: string };
}

/* ═══════════════ Helpers ═══════════════ */

const fmtAmount = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

const FULL_MONTHS_RU = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

function formatDate(iso: string | null | undefined): string {
    if (!iso) {
return '—';
}

    const [y, m, d] = iso.split('T')[0].split('-').map(Number);

    if (!y || !m || !d) {
return '—';
}

    return `${d} ${FULL_MONTHS_RU[m - 1]} ${y}`;
}

function formatPeriod(start: string | null, end: string | null): string {
    if (!start || !end) {
return '—';
}

    return `${formatDate(start)} — ${formatDate(end)}`;
}

const TABS = [
    { key: 'awaiting', label: 'Ожидают оформления' },
    { key: 'attached', label: 'Прикреплённые' },
    { key: 'refund_review', label: 'Требуют проверки возврата' },
];

/* ═══════════════ Page ═══════════════ */

export default function Receipts() {
    const { receipts, tab, canUpdate, flash } = usePage().props as ReceiptsProps;

    const [editing, setEditing] = useState<ReceiptRow | null>(null);
    const [urlValue, setUrlValue] = useState('');
    const [urlError, setUrlError] = useState('');
    const [saving, setSaving] = useState(false);

    function switchTab(key: string) {
        router.get('/admin-root/receipts', { tab: key }, { preserveState: true });
    }

    function openEdit(row: ReceiptRow) {
        setEditing(row);
        setUrlValue(row.receipt_url || '');
        setUrlError('');
    }

    function handleSaveUrl() {
        if (!editing) {
return;
}

        if (!urlValue.trim()) {
            setUrlError('Укажите ссылку на чек');

            return;
        }

        setSaving(true);
        setUrlError('');
        router.put(`/admin-root/receipts/${editing.id}/attach`, { receipt_url: urlValue.trim() }, {
            preserveState: true,
            onSuccess: () => setEditing(null),
            onError: (errors: Record<string, string>) => setUrlError(Object.values(errors).flat().join(', ') || 'Ошибка сервера'),
            onFinish: () => setSaving(false),
        });
    }

    function handleMarkReviewed(row: ReceiptRow) {
        router.post(`/admin-root/receipts/${row.id}/refund-reviewed`, {}, { preserveState: true });
    }

    return (
        <>
            <Head title="Чеки" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-4xl px-4 py-10">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Чеки</h1>
                            <p className="mt-1.5 text-sm text-[#62615F]">
                                Ручное оформление чеков НПД за оплату подписки
                            </p>
                        </div>
                    </div>

                    <SuperAdminNav current="receipts" />

                    {flash?.success && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#16875E]">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#C44351]">
                            {flash.error}
                        </div>
                    )}

                    {/* Tabs */}
                    <div className="mt-5 flex flex-wrap gap-2 text-sm">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => switchTab(t.key)}
                                className={
                                    t.key === tab
                                        ? 'rounded-full bg-[#181818] px-3 py-1.5 font-semibold text-white'
                                        : 'rounded-full border border-[#E7E4DF] bg-white px-3 py-1.5 text-[#62615F] hover:text-[#181818]'
                                }
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {/* List */}
                    <div className="mt-5 space-y-3">
                        {receipts.data.length === 0 && (
                            <div className="rounded-2xl border border-[#E7E4DF] bg-white px-4 py-8 text-center text-sm text-[#8E8A85]">
                                {tab === 'awaiting' && 'Нет чеков, ожидающих оформления.'}
                                {tab === 'attached' && 'Нет прикреплённых чеков.'}
                                {tab === 'refund_review' && 'Нет чеков, требующих проверки возврата.'}
                            </div>
                        )}

                        {receipts.data.map((row) => (
                            <div
                                key={row.id}
                                className="rounded-2xl border border-[#E7E4DF] bg-white p-4"
                                data-testid="receipt-card"
                            >
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0">
                                        <div className="font-semibold text-[#181818]">
                                            {row.attempt.owner_name || 'Без имени'}
                                        </div>
                                        <div className="mt-0.5 text-sm text-[#62615F]">
                                            {row.attempt.owner_email || row.attempt.workspace_id || '—'}
                                        </div>
                                        <div className="mt-2 text-sm text-[#181818]">
                                            {fmtAmount(row.attempt.amount)} · {row.attempt.tariff_name || 'Тариф'}
                                        </div>
                                        <div className="mt-1 text-sm text-[#62615F]">
                                            Период: {formatPeriod(row.attempt.period_start, row.attempt.period_end)}
                                        </div>
                                        <div className="mt-1 text-sm text-[#62615F]">
                                            Подтверждено в ИРСИ: {formatDate(row.attempt.confirmed_at)}
                                        </div>
                                        <div className="mt-1 text-xs text-[#8E8A85]">
                                            {row.attempt.origin === 'renewal' ? 'Продление' : 'Оплата'}
                                            {' · '}{row.attempt.provider}
                                            {row.attempt.payment_env ? ` · ${row.attempt.payment_env}` : ''}
                                        </div>
                                        {row.refund_review_required && (
                                            <div className="mt-2 inline-flex rounded-full bg-[#FBE9EA] px-2.5 py-1 text-xs font-medium text-[#C44351]">
                                                Возврат — требует ручной проверки
                                            </div>
                                        )}
                                        {row.status === 'attached' && !row.refund_review_required && (
                                            <div className="mt-2 inline-flex rounded-full bg-[#E5F5EE] px-2.5 py-1 text-xs font-medium text-[#16875E]">
                                                Прикреплён
                                            </div>
                                        )}
                                        {row.status === 'awaiting_manual' && !row.refund_review_required && (
                                            <div className="mt-2 inline-flex rounded-full bg-[#F3EFE9] px-2.5 py-1 text-xs font-medium text-[#62615F]">
                                                Ожидает оформления
                                            </div>
                                        )}
                                    </div>

                                    <div className="flex flex-col gap-2 sm:items-end sm:text-right">
                                        {row.status === 'attached' && row.receipt_url && (
                                            <a
                                                href={row.receipt_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-sm font-medium text-[#FF5A1F] hover:underline"
                                            >
                                                Открыть чек ↗
                                            </a>
                                        )}
                                        {row.attached_at && (
                                            <div className="text-xs text-[#8E8A85]">
                                                Прикреплён: {formatDate(row.attached_at)}
                                                {row.attached_by_user ? ` · ${row.attached_by_user.name}` : ''}
                                            </div>
                                        )}
                                        {canUpdate && (
                                            <button
                                                type="button"
                                                onClick={() => openEdit(row)}
                                                className="rounded-xl border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm font-medium text-[#181818] hover:bg-[#F7F5F1]"
                                                data-testid="receipt-edit-btn"
                                            >
                                                {row.receipt_url ? 'Изменить ссылку' : 'Прикрепить ссылку'}
                                            </button>
                                        )}
                                        {canUpdate && tab === 'refund_review' && row.refund_review_required && (
                                            <button
                                                type="button"
                                                onClick={() => handleMarkReviewed(row)}
                                                className="rounded-xl bg-[#FF5A1F] px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90"
                                                data-testid="receipt-reviewed-btn"
                                            >
                                                Проверено
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {row.status === 'attached' && row.receipt_url && (
                                    <div className="mt-3 break-all rounded-xl bg-[#F7F5F1] px-3 py-2 text-xs text-[#62615F]">
                                        {row.receipt_url}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    {/* Pagination */}
                    {receipts.last_page > 1 && (
                        <div className="mt-5 flex items-center justify-between text-sm">
                            <span className="text-[#8E8A85]">
                                Страница {receipts.current_page} из {receipts.last_page} · всего {receipts.total}
                            </span>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={receipts.current_page <= 1}
                                    onClick={() => router.get('/admin-root/receipts', { tab, page: receipts.current_page - 1 }, { preserveState: true })}
                                    className="rounded-xl border border-[#E7E4DF] bg-white px-3 py-1.5 disabled:opacity-40"
                                >
                                    Назад
                                </button>
                                <button
                                    type="button"
                                    disabled={receipts.current_page >= receipts.last_page}
                                    onClick={() => router.get('/admin-root/receipts', { tab, page: receipts.current_page + 1 }, { preserveState: true })}
                                    className="rounded-xl border border-[#E7E4DF] bg-white px-3 py-1.5 disabled:opacity-40"
                                >
                                    Вперёд
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Edit dialog */}
                    {editing && (
                        <div
                            className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
                            onClick={() => setEditing(null)}
                            data-testid="receipt-url-dialog"
                        >
                            <div
                                className="w-full max-w-lg rounded-2xl bg-white p-5"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <h2 className="text-lg font-bold text-[#181818]">
                                    {editing.receipt_url ? 'Изменить ссылку на чек' : 'Прикрепить ссылку на чек'}
                                </h2>
                                <p className="mt-1 text-sm text-[#62615F]">
                                    Ссылка вида https://lknpd.nalog.ru/api/v1/receipt/…/print.
                                    Она будет показана покупателю в кабинете.
                                </p>
                                <textarea
                                    value={urlValue}
                                    onChange={(e) => setUrlValue(e.target.value)}
                                    rows={3}
                                    className="mt-3 w-full rounded-xl border border-[#E7E4DF] px-3 py-2 text-sm focus:border-[#FF5A1F] focus:outline-none"
                                    placeholder="https://lknpd.nalog.ru/api/v1/receipt/…/print"
                                    data-testid="receipt-url-input"
                                />
                                {urlError && (
                                    <div className="mt-2 text-sm text-[#C44351]" data-testid="receipt-url-error">
                                        {urlError}
                                    </div>
                                )}
                                <div className="mt-4 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditing(null)}
                                        className="rounded-xl border border-[#E7E4DF] px-4 py-2 text-sm"
                                    >
                                        Отмена
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleSaveUrl}
                                        disabled={saving}
                                        className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                        data-testid="receipt-url-save"
                                    >
                                        {saving ? 'Сохранение…' : 'Сохранить'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
