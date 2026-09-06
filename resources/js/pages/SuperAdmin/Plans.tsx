import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

interface Plan {
    id: string;
    code: string;
    name: string;
    price_monthly: number;
    max_appointments_per_month: number | null;
    max_masters: number | null;
    is_active: boolean;
}

interface PageProps {
    plans: Plan[];
    flash?: { success?: string };
    [key: string]: unknown;
}

export default function Plans() {
    const { plans, flash } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<string | null>(null);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);

    function startEdit(plan: Plan) {
        setEditing(plan.id);
        setValue(plan.max_appointments_per_month?.toString() ?? '');
    }

    function handleSave(plan: Plan) {
        setSaving(true);
        const parsed = value.trim() === '' ? null : parseInt(value, 10);

        router.put(`/admin-root/plans/${plan.id}`, {
            max_appointments_per_month: parsed,
        }, {
            preserveScroll: true,
            onFinish: () => {
                setSaving(false);
                setEditing(null);
            },
        });
    }

    return (
        <>
            <Head title="Управление тарифами" />
            <div className="mx-auto max-w-3xl px-4 py-8">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-zinc-50">Тарифы</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                    Управление лимитами тарифных планов. Изменения применяются мгновенно для всех пользователей.
                </p>

                {flash?.success && (
                    <div className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                        {flash.success}
                    </div>
                )}

                <div className="mt-6 space-y-4">
                    {plans.map((plan) => (
                        <div key={plan.id} className="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-900 dark:text-zinc-100">{plan.name}</h2>
                                    <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
                                        {plan.price_monthly > 0 ? `${plan.price_monthly} ₽/мес` : 'Бесплатно'}
                                    </p>
                                </div>
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${plan.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-400'}`}>
                                    {plan.is_active ? 'Активен' : 'Неактивен'}
                                </span>
                            </div>

                            <div className="mt-4 flex items-center gap-4">
                                <div className="text-sm text-slate-600 dark:text-zinc-400">
                                    Записей в месяц:
                                </div>
                                {plan.code !== 'start' ? (
                                    <span className="font-semibold text-slate-900 dark:text-zinc-100">
                                        Безлимит
                                    </span>
                                ) : editing === plan.id ? (
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="number"
                                            min="1"
                                            value={value}
                                            onChange={(e) => setValue(e.target.value)}
                                            className="w-24 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                            autoFocus
                                        />
                                        <button
                                            type="button"
                                            onClick={() => handleSave(plan)}
                                            disabled={saving}
                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                                        >
                                            {saving ? '…' : 'Сохранить'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setEditing(null)}
                                            className="rounded-lg px-3 py-1.5 text-sm text-slate-500 hover:bg-slate-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                        >
                                            Отмена
                                        </button>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold text-slate-900 dark:text-zinc-100">
                                            {plan.max_appointments_per_month ?? '—'}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => startEdit(plan)}
                                            className="rounded-lg px-2 py-1 text-sm text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20"
                                        >
                                            Изменить
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
