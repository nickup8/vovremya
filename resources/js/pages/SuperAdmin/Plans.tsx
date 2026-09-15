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
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

export default function Plans() {
    const { plans, flash } = usePage<PageProps>().props;

    const startPlan = plans.find((p) => p.code === 'start');
    const proPlan = plans.find((p) => p.code === 'pro');

    return (
        <>
            <Head title="Тарифы — ИРСИ" />
            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-2xl px-4 py-10">
                    <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Тарифы</h1>
                    <p className="mt-1.5 text-sm text-[#62615F]">
                        Настройки тарифов ИРСИ
                    </p>

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

                    <div className="mt-6 space-y-5">
                        {startPlan && <StartCard plan={startPlan} />}
                        {proPlan && <ProCard plan={proPlan} />}
                    </div>
                </div>
            </div>
        </>
    );
}

function StartCard({ plan }: { plan: Plan }) {
    const [value, setValue] = useState(plan.max_appointments_per_month?.toString() ?? '');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function handleSave() {
        setSaving(true);
        setErrors({});

        router.put(`/admin-root/plans/${plan.id}`, {
            max_appointments_per_month: parseInt(value, 10),
        }, {
            preserveScroll: true,
            onSuccess: () => setErrors({}),
            onError: (errs) => setErrors(errs as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <div className="rounded-2xl border border-[#E7E4DF] bg-white p-6">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold tracking-tight text-[#181818]">{plan.name}</h2>
                <span className="rounded-full bg-[#F7F5F1] px-3 py-1 text-xs font-semibold text-[#62615F]">
                    {plan.price_monthly > 0 ? `${plan.price_monthly} ₽/мес` : 'Бесплатно'}
                </span>
            </div>

            <div className="mt-5 space-y-4">
                <div>
                    <label className="block text-sm font-semibold text-[#181818]">
                        Лимит записей в месяц
                    </label>
                    <div className="mt-1.5 flex items-center gap-3">
                        <input
                            type="number"
                            min="1"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                            className="w-28 rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving}
                            className="rounded-xl bg-[#FF5A1F] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                        >
                            {saving ? 'Сохранение…' : 'Сохранить'}
                        </button>
                    </div>
                    {errors.max_appointments_per_month && (
                        <p className="mt-1.5 text-xs text-[#C44351]">{errors.max_appointments_per_month}</p>
                    )}
                </div>
            </div>
        </div>
    );
}

function ProCard({ plan }: { plan: Plan }) {
    const [value, setValue] = useState(plan.price_monthly.toString());
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function handleSave() {
        setSaving(true);
        setErrors({});

        router.put(`/admin-root/plans/${plan.id}`, {
            price_monthly: parseFloat(value),
        }, {
            preserveScroll: true,
            onSuccess: () => setErrors({}),
            onError: (errs) => setErrors(errs as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <div className="rounded-2xl border border-[#E7E4DF] bg-white p-6">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold tracking-tight text-[#181818]">{plan.name}</h2>
                <span className="rounded-full bg-[#FF5A1F]/10 px-3 py-1 text-xs font-semibold text-[#FF5A1F]">
                    Текущая: {plan.price_monthly} ₽/мес
                </span>
            </div>

            <div className="mt-5 space-y-4">
                <div>
                    <label className="block text-sm font-semibold text-[#181818]">
                        Цена в месяц
                    </label>
                    <div className="mt-1.5 flex items-center gap-3">
                        <div className="relative">
                            <input
                                type="number"
                                min="0"
                                step="1"
                                value={value}
                                onChange={(e) => setValue(e.target.value)}
                                className="w-32 rounded-xl border border-[#E7E4DF] bg-white py-2.5 pl-3.5 pr-9 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                            />
                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-[#8E8A85]">₽</span>
                        </div>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving}
                            className="rounded-xl bg-[#FF5A1F] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                        >
                            {saving ? 'Сохранение…' : 'Сохранить'}
                        </button>
                    </div>
                    {errors.price_monthly && (
                        <p className="mt-1.5 text-xs text-[#C44351]">{errors.price_monthly}</p>
                    )}
                </div>

                <div className="border-t border-[#F0EEEA] pt-4">
                    <p className="text-sm text-[#8E8A85]">
                        Записей: <span className="font-semibold text-[#181818]">Без ограничений</span>
                    </p>
                </div>
            </div>
        </div>
    );
}
