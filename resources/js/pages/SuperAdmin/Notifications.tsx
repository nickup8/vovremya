import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';
import SystemNotificationSendDialog from '@/components/super-admin/SystemNotificationSendDialog';
import { toast } from 'sonner';

interface NotificationMessage {
    id: string;
    title: string;
    body: string;
    created_at: string;
    updated_at: string;
    notifications_count: number;
    read_count: number;
}

interface Recipient {
    id: string;
    name: string;
    phone: string | null;
}

interface PaginatedMessages {
    data: NotificationMessage[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface NotificationsProps {
    messages: PaginatedMessages;
    recipients: Recipient[];
    flash?: { success?: string; error?: string };
}

function formatDateTime(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function Notifications() {
    const { messages, recipients, flash, platformAdmin } = usePage().props as NotificationsProps & {
        platformAdmin: { isRoot: boolean; permissions: string[] };
    };

    const canSend = platformAdmin.isRoot || platformAdmin.permissions.includes('notifications.send');

    const [detailMessage, setDetailMessage] = useState<NotificationMessage | null>(null);

    const [editMessage, setEditMessage] = useState<NotificationMessage | null>(null);
    const [editTitle, setEditTitle] = useState('');
    const [editBody, setEditBody] = useState('');
    const [editLoading, setEditLoading] = useState(false);
    const [editErrors, setEditErrors] = useState<Record<string, string>>({});

    const [deleteMessage, setDeleteMessage] = useState<NotificationMessage | null>(null);
    const [deleteLoading, setDeleteLoading] = useState(false);

    const [sendOpen, setSendOpen] = useState(false);

    function handleEditSubmit() {
        if (!editMessage) return;
        setEditLoading(true);
        setEditErrors({});
        router.put(`/admin-root/notifications/${editMessage.id}`, {
            title: editTitle,
            body: editBody,
        }, {
            preserveState: true,
            onSuccess: () => {
                setEditMessage(null);
                toast.success('Сообщение обновлено');
            },
            onError: (errors: Record<string, string>) => {
                const mapped: Record<string, string> = {};
                for (const [k, v] of Object.entries(errors)) {
                    mapped[k] = Array.isArray(v) ? v[0] : String(v);
                }
                setEditErrors(mapped);
            },
            onFinish: () => setEditLoading(false),
        });
    }

    function handleDeleteSubmit() {
        if (!deleteMessage) return;
        setDeleteLoading(true);
        router.delete(`/admin-root/notifications/${deleteMessage.id}`, {
            preserveState: true,
            onSuccess: () => {
                setDeleteMessage(null);
                setDetailMessage(null);
                toast.success('Сообщение удалено');
            },
            onFinish: () => setDeleteLoading(false),
        });
    }

    return (
        <>
            <Head title="Уведомления — ИРСИ" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-4xl px-4 py-10">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Сервисные уведомления</h1>
                            <p className="mt-1.5 text-sm text-[#62615F]">
                                Отправлено сообщений: {messages.total}
                            </p>
                        </div>
                        {canSend && (
                            <button
                                onClick={() => setSendOpen(true)}
                                className="rounded-xl bg-[#FF5A1F] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14]"
                            >
                                Отправить уведомление
                            </button>
                        )}
                    </div>

                    <SuperAdminNav current="notifications" />

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

                    {messages.data.length === 0 ? (
                        <div className="mt-10 text-center">
                            <p className="text-sm font-semibold text-[#181818]">Нет отправленных сообщений</p>
                            <p className="mt-1 text-sm text-[#8E8A85]">
                                Отправьте первое сервисное уведомление мастерам.
                            </p>
                            {canSend && (
                                <button
                                    onClick={() => setSendOpen(true)}
                                    className="mt-4 rounded-xl bg-[#FF5A1F] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14]"
                                >
                                    Отправить уведомление
                                </button>
                            )}
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 overflow-x-auto rounded-2xl border border-[#E7E4DF] bg-white">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-[#F0EEEA] text-left">
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Заголовок</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Отправлено</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Получателей</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Прочитано</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Не прочитано</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#F0EEEA]">
                                        {messages.data.map((msg) => {
                                            const unreadCount = msg.notifications_count - msg.read_count;
                                            const wasEdited = msg.updated_at !== msg.created_at;

                                            return (
                                                <tr key={msg.id} className="align-top">
                                                    <td className="px-4 py-3">
                                                        <p className="font-medium text-[#181818]">{msg.title}</p>
                                                        {wasEdited && (
                                                            <span className="text-xs text-[#8E8A85]">ред.</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-[#62615F]">
                                                        {formatDateTime(msg.created_at)}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-[#62615F]">
                                                        {msg.notifications_count}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-[#16875E]">
                                                        {msg.read_count}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-[#C44351]">
                                                        {unreadCount > 0 ? unreadCount : '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-wrap gap-1">
                                                            <button
                                                                onClick={() => setDetailMessage(msg)}
                                                                className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                            >
                                                                Открыть
                                                            </button>
                                                            <button
                                                                onClick={() => {
                                                                    setEditMessage(msg);
                                                                    setEditTitle(msg.title);
                                                                    setEditBody(msg.body);
                                                                    setEditErrors({});
                                                                }}
                                                                className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                            >
                                                                Редактировать
                                                            </button>
                                                            <button
                                                                onClick={() => setDeleteMessage(msg)}
                                                                className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#C44351] hover:bg-red-50"
                                                            >
                                                                Удалить
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>

                            {messages.last_page > 1 && (
                                <div className="mt-4 flex items-center justify-between text-sm">
                                    <span className="text-[#8E8A85]">
                                        {messages.total} {messages.total % 10 === 1 && messages.total !== 11 ? 'сообщение' : (messages.total % 10 >= 2 && messages.total % 10 <= 4 && (messages.total < 12 || messages.total > 14)) ? 'сообщения' : 'сообщений'}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        {messages.current_page > 1 && (
                                            <button
                                                onClick={() => router.get('/admin-root/notifications', { page: String(messages.current_page - 1) }, { preserveState: true })}
                                                className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                            >
                                                Назад
                                            </button>
                                        )}
                                        <span className="text-[#62615F]">
                                            {messages.current_page} / {messages.last_page}
                                        </span>
                                        {messages.current_page < messages.last_page && (
                                            <button
                                                onClick={() => router.get('/admin-root/notifications', { page: String(messages.current_page + 1) }, { preserveState: true })}
                                                className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                            >
                                                Вперёд
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Send Dialog */}
            <SystemNotificationSendDialog
                open={sendOpen}
                onClose={() => setSendOpen(false)}
                recipients={recipients}
            />

            {/* Detail Modal */}
            {detailMessage && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setDetailMessage(null)}>
                    <div className="w-full max-w-lg max-h-[80vh] flex flex-col rounded-2xl border border-[#E7E4DF] bg-white shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between border-b border-[#F0EEEA] px-6 py-4">
                            <h2 className="text-lg font-bold tracking-tight text-[#181818]">{detailMessage.title}</h2>
                            <button onClick={() => setDetailMessage(null)} className="text-[#8E8A85] hover:text-[#181818]">
                                <svg className="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div className="flex-1 overflow-y-auto px-6 py-4">
                            <p className="text-sm leading-6 text-[#181818] whitespace-pre-wrap break-words">{detailMessage.body}</p>
                        </div>
                        <div className="border-t border-[#F0EEEA] px-6 py-3">
                            <div className="flex items-center justify-between text-xs text-[#8E8A85]">
                                <span>Отправлено: {formatDateTime(detailMessage.created_at)}</span>
                                <span>
                                    Получателей: {detailMessage.notifications_count} ·
                                    Прочитано: {detailMessage.read_count} ·
                                    Не прочитано: {detailMessage.notifications_count - detailMessage.read_count}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Edit Modal */}
            {editMessage && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setEditMessage(null)}>
                    <div className="w-full max-w-md rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Редактировать сообщение</h2>
                        <p className="mt-1 text-sm text-[#62615F]">
                            Изменения отразятся у всех получателей
                        </p>

                        <label className="mt-4 block text-sm font-semibold text-[#181818]">Заголовок</label>
                        <input
                            type="text"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        {editErrors.title && <p className="mt-1.5 text-xs text-[#C44351]">{editErrors.title}</p>}

                        <label className="mt-4 block text-sm font-semibold text-[#181818]">Сообщение</label>
                        <textarea
                            value={editBody}
                            onChange={(e) => setEditBody(e.target.value)}
                            rows={6}
                            className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        {editErrors.body && <p className="mt-1.5 text-xs text-[#C44351]">{editErrors.body}</p>}

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setEditMessage(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={handleEditSubmit}
                                disabled={editLoading || !editTitle.trim() || !editBody.trim()}
                                className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                            >
                                {editLoading ? 'Сохранение…' : 'Сохранить'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Delete Confirmation Modal */}
            {deleteMessage && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setDeleteMessage(null)}>
                    <div className="w-full max-w-sm rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Удалить сообщение?</h2>
                        <p className="mt-1 text-sm text-[#62615F]">
                            Сообщение «{deleteMessage.title}» исчезнет у всех {deleteMessage.notifications_count} получателей.
                        </p>

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setDeleteMessage(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={handleDeleteSubmit}
                                disabled={deleteLoading}
                                className="rounded-xl bg-[#C44351] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#A83843] disabled:opacity-50"
                            >
                                {deleteLoading ? 'Удаление…' : 'Удалить'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
