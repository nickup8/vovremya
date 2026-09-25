import { router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { toast } from 'sonner';

interface Recipient {
    id: string;
    name: string;
    phone: string | null;
}

interface Props {
    open: boolean;
    onClose: () => void;
    recipients: Recipient[];
    fixedRecipient?: Recipient | null;
}

export default function SystemNotificationSendDialog({ open, onClose, recipients, fixedRecipient }: Props) {
    const [recipientType, setRecipientType] = useState<'all' | 'user'>(fixedRecipient ? 'user' : 'all');
    const [selectedUserId, setSelectedUserId] = useState<string>(fixedRecipient?.id ?? '');
    const [searchQuery, setSearchQuery] = useState('');
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const searchRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open && fixedRecipient) {
            setRecipientType('user');
            setSelectedUserId(fixedRecipient.id);
        } else if (open) {
            setRecipientType('all');
            setSelectedUserId('');
        }
    }, [open, fixedRecipient]);

    useEffect(() => {
        if (open && recipientType === 'user' && !fixedRecipient) {
            setTimeout(() => searchRef.current?.focus(), 50);
        }
    }, [open, recipientType, fixedRecipient]);

    if (!open) return null;

    const filtered = recipients.filter((r) => {
        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase();
            return r.name.toLowerCase().includes(q) || (r.phone && r.phone.includes(q));
        }
        return true;
    });

    const selectedUser = fixedRecipient || recipients.find((r) => r.id === selectedUserId);

    function handleSubmit() {
        setLoading(true);
        setErrors({});

        router.post('/admin-root/notifications', {
            recipient_type: recipientType,
            user_id: recipientType === 'user' ? selectedUserId : undefined,
            title,
            body,
        }, {
            preserveState: true,
            onSuccess: () => {
                onClose();
                setTitle('');
                setBody('');
                setSearchQuery('');
                toast.success('Уведомление отправлено');
            },
            onError: (err: Record<string, string>) => {
                const mapped: Record<string, string> = {};
                for (const [k, v] of Object.entries(err)) {
                    mapped[k] = Array.isArray(v) ? v[0] : String(v);
                }
                setErrors(mapped);
            },
            onFinish: () => setLoading(false),
        });
    }

    const canSubmit = title.trim() && body.trim() && (recipientType === 'all' || selectedUserId);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                <h2 className="text-lg font-bold tracking-tight text-[#181818]">Отправить уведомление</h2>

                {!fixedRecipient && (
                    <div className="mt-4 flex gap-2">
                        <button
                            onClick={() => { setRecipientType('all'); setSelectedUserId(''); setSearchQuery(''); }}
                            className={`rounded-xl px-4 py-2 text-sm font-semibold transition-colors ${
                                recipientType === 'all'
                                    ? 'bg-[#FF5A1F] text-white'
                                    : 'border border-[#E7E4DF] bg-white text-[#62615F] hover:bg-[#F7F5F1]'
                            }`}
                        >
                            Всем мастерам
                        </button>
                        <button
                            onClick={() => setRecipientType('user')}
                            className={`rounded-xl px-4 py-2 text-sm font-semibold transition-colors ${
                                recipientType === 'user'
                                    ? 'bg-[#FF5A1F] text-white'
                                    : 'border border-[#E7E4DF] bg-white text-[#62615F] hover:bg-[#F7F5F1]'
                            }`}
                        >
                            Конкретному мастеру
                        </button>
                    </div>
                )}

                {fixedRecipient ? (
                    <p className="mt-3 text-sm text-[#62615F]">
                        Получатель: <span className="font-medium text-[#181818]">{fixedRecipient.name}</span>
                    </p>
                ) : recipientType === 'user' ? (
                    <div className="mt-3">
                        <input
                            ref={searchRef}
                            type="text"
                            placeholder="Поиск по имени или телефону"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        <div className="mt-2 max-h-40 overflow-y-auto rounded-xl border border-[#E7E4DF]">
                            {filtered.length === 0 ? (
                                <p className="px-3.5 py-2.5 text-sm text-[#8E8A85]">Мастера не найдены</p>
                            ) : (
                                filtered.map((r) => (
                                    <button
                                        key={r.id}
                                        onClick={() => setSelectedUserId(r.id)}
                                        className={`flex w-full items-center justify-between px-3.5 py-2.5 text-left text-sm transition-colors ${
                                            selectedUserId === r.id
                                                ? 'bg-[#FF5A1F]/10 text-[#FF5A1F]'
                                                : 'hover:bg-[#F7F5F1] text-[#181818]'
                                        }`}
                                    >
                                        <span>{r.name}</span>
                                        {r.phone && <span className="text-xs text-[#8E8A85]">{r.phone}</span>}
                                    </button>
                                ))
                            )}
                        </div>
                    </div>
                ) : (
                    <p className="mt-3 text-sm text-[#62615F]">
                        Получатели: <span className="font-medium text-[#181818]">Все активные мастера</span>
                    </p>
                )}

                <label className="mt-4 block text-sm font-semibold text-[#181818]">Заголовок</label>
                <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Заголовок уведомления"
                    className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                />
                {errors.title && <p className="mt-1.5 text-xs text-[#C44351]">{errors.title}</p>}

                <label className="mt-4 block text-sm font-semibold text-[#181818]">Сообщение</label>
                <textarea
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder="Текст уведомления"
                    rows={4}
                    className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                />
                {errors.body && <p className="mt-1.5 text-xs text-[#C44351]">{errors.body}</p>}

                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onClose}
                        className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                    >
                        Отмена
                    </button>
                    <button
                        onClick={handleSubmit}
                        disabled={loading || !canSubmit}
                        className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                    >
                        {loading ? 'Отправка…' : 'Отправить'}
                    </button>
                </div>
            </div>
        </div>
    );
}
