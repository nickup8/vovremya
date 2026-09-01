import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

export interface BlockedTime {
    id: string;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

const BLOCKED_REASONS = [
    'Отпуск',
    'Больничный',
    'Обед',
    'Личное время',
    'Другое',
];

export default function BlockedTimesCard({ masterId }: { masterId?: string }) {
    const { blockedTimes: rawBlockedTimes } = usePage<{ blockedTimes: BlockedTime[] }>().props;
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
        <>
            <div className="flex items-center justify-between gap-4">
                <div>
                    <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                        Недоступное время
                    </p>
                    <p className="text-[12px] text-[var(--color-graphite)]">
                        Блокировки отпуска, обедов и прочего
                    </p>
                </div>
                <Button
                    type="button"
                    className="h-9 shrink-0 rounded-[10px] bg-[var(--color-orange)] px-3.5 text-[12px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                    onClick={() => setDialogOpen(true)}
                >
                    <Plus className="size-3.5" />
                    Добавить
                </Button>
            </div>

            {blockedTimes.length === 0 ? (
                <p className="py-4 text-center text-[13px] text-[var(--color-graphite)]">
                    Нет активных блокировок
                </p>
            ) : (
                <div className="mt-3 space-y-2">
                    {blockedTimes.map((bt) => (
                        <div
                            key={bt.id}
                            className="flex min-h-[54px] items-center justify-between rounded-[10px] bg-[var(--color-warm)] px-3 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                    {bt.reason}
                                </p>
                                <p className="text-[12px] text-[var(--color-graphite)]">
                                    {new Date(bt.start_datetime).toLocaleDateString('ru-RU')} — {new Date(bt.end_datetime).toLocaleDateString('ru-RU')}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => handleDelete(bt.id)}
                                className="shrink-0 rounded-[8px] p-1.5 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-red-bg)] hover:text-[var(--color-red)]"
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
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Причина
                            </label>
                            <Select value={reason} onValueChange={setReason}>
                                <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {BLOCKED_REASONS.map((r) => (
                                        <SelectItem key={r} value={r}>{r}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                    С
                                </label>
                                <input
                                    type="datetime-local"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)] focus:ring-2 focus:ring-[var(--color-orange)] focus:ring-offset-0"
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                    По
                                </label>
                                <input
                                    type="datetime-local"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)] focus:ring-2 focus:ring-[var(--color-orange)] focus:ring-offset-0"
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-10 rounded-[10px] border-[var(--color-line)] px-4 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                                onClick={() => setDialogOpen(false)}
                            >
                                Отмена
                            </Button>
                            <Button
                                type="button"
                                onClick={handleAdd}
                                disabled={!startDate || !endDate}
                                className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                            >
                                Добавить
                            </Button>
                        </DialogFooter>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
