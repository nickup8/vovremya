import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    UserPlus, Crown, Send, MessageCircle, Copy, Check, Users, Sparkles, Trash2, Shield,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AdminLayout from '@/layouts/AdminLayout';
import { getInitials } from '@/lib/utils';
import type { PageProps } from '@/types/app';

/* ═══════════════ Types ═══════════════ */

interface Master {
    id: string;
    name: string;
    avatar_url: string | null;
    telegram_id: string | null;
    max_id: string | null;
    is_owner: boolean;
    is_current_user: boolean;
    role: 'owner' | 'admin' | 'master';
    is_bookable: boolean;
    has_future_appointments: boolean;
}

interface TeamPageProps extends PageProps {
    masters: Master[];
    max_masters: number | null;
    can_manage_team: boolean;
    can_invite_admins: boolean;
}

/* ═══════════════ Empty State ═══════════════ */

function EmptyState({ onOpenInvite }: { onOpenInvite: () => void }) {
    return (
        <div className="flex flex-col items-center justify-center py-20">
            <div className="relative mb-8">
                <div className="rounded-full bg-gradient-to-br from-blue-500/10 to-indigo-500/10 p-8 dark:from-blue-500/5 dark:to-indigo-500/5">
                    <Users className="size-16 text-blue-500/60 dark:text-blue-400/40" />
                </div>
                <div className="absolute -right-2 -top-2 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 p-2">
                    <Sparkles className="size-4 text-white" />
                </div>
            </div>

            <h2 className="mb-3 text-xl font-bold text-slate-900 dark:text-zinc-100">
                Расширяйте бизнес с тарифом Студия
            </h2>
            <p className="mb-8 max-w-md text-center text-sm leading-relaxed text-slate-500 dark:text-zinc-400">
                Добавьте до 5 мастеров и управляйте всем салоном в одном окне.
                Календарь, аналитика и уведомления — для всей команды.
            </p>

            <Button
                onClick={onOpenInvite}
                className="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition-all hover:from-blue-700 hover:to-indigo-700 hover:shadow-xl hover:shadow-blue-500/30"
            >
                <UserPlus className="size-4" />
                Пригласить первого мастера
            </Button>
        </div>
    );
}

/* ═══════════════ Master Card ═══════════════ */

function MasterCard({
    master,
    canManageTeam,
    onDetach,
    onToggleBookable,
}: {
    master: Master;
    canManageTeam: boolean;
    onDetach: (master: Master) => void;
    onToggleBookable: (master: Master, value: boolean) => void;
}) {
    const initials = getInitials(master.name);
    const canDetach = canManageTeam && !master.is_owner && !master.is_current_user;
    const canToggleBookable = canManageTeam && master.is_owner;

    return (
        <div className="group overflow-hidden rounded-2xl border border-slate-200/60 bg-white/50 p-5 backdrop-blur-md transition-all hover:border-blue-200 hover:shadow-lg hover:shadow-blue-500/5 dark:border-zinc-700/50 dark:bg-zinc-900/50 dark:hover:border-blue-800/50 dark:hover:shadow-blue-500/5">
            <div className="flex items-center gap-4">
                <Avatar className="size-12 ring-2 ring-white/80 dark:ring-zinc-800/80">
                    <AvatarImage src={master.avatar_url ?? undefined} alt={master.name} className="object-cover" />
                    <AvatarFallback className="bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white">
                        {initials}
                    </AvatarFallback>
                </Avatar>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-slate-900 dark:text-zinc-100">
                        {master.name}
                    </h3>
                    <div className="mt-1 flex items-center gap-1.5">
                        {master.is_owner && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:bg-amber-950/30 dark:text-amber-400">
                                <Crown className="size-2.5" />
                                Владелец
                            </span>
                        )}
                        {!master.is_owner && master.role === 'admin' && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-600 dark:bg-blue-950/30 dark:text-blue-400">
                                <Shield className="size-2.5" />
                                Администратор
                            </span>
                        )}
                        {!master.is_bookable && !master.is_owner && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-zinc-800 dark:text-zinc-400">
                                Скрыт
                            </span>
                        )}
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                        {master.telegram_id && (
                            <span className="flex items-center gap-1 text-[10px] font-medium text-blue-500 dark:text-blue-400">
                                <Send className="size-2.5" />
                                Telegram
                            </span>
                        )}
                        {master.max_id && (
                            <span className="flex items-center gap-1 text-[10px] font-medium text-slate-400 dark:text-zinc-500">
                                <MessageCircle className="size-2.5" />
                                MAX
                            </span>
                        )}
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-3">
                    {canToggleBookable && (
                        <div className="flex items-center gap-2">
                            <Switch
                                checked={master.is_bookable}
                                onCheckedChange={(checked) => onToggleBookable(master, checked)}
                                aria-label="Доступен для записи"
                            />
                            <span className="text-xs text-slate-500 dark:text-zinc-400 whitespace-nowrap">
                                Принимаю записи от клиентов
                            </span>
                        </div>
                    )}
                    {canDetach && (
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => onDetach(master)}
                            className="text-slate-400 hover:text-red-500 dark:text-zinc-500 dark:hover:text-red-400"
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Main Team Page ═══════════════ */

export default function TeamPage() {
    const { masters, max_masters, can_manage_team, can_invite_admins, auth } = usePage<TeamPageProps>().props;
    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [inviteLink, setInviteLink] = useState('');
    const [inviteRole, setInviteRole] = useState<'master' | 'admin'>('master');
    const [loading, setLoading] = useState(false);
    const [copied, setCopied] = useState(false);
    const [detachTarget, setDetachTarget] = useState<Master | null>(null);
    const [detachTargetId, setDetachTargetId] = useState<string>('');
    const [detachLoading, setDetachLoading] = useState(false);

    const currentCount = masters.length;
    const hasLimit = max_masters !== null && max_masters !== undefined;
    const isLimitReached = hasLimit && currentCount >= max_masters!;

    const receivers = detachTarget
        ? masters.filter((m) => m.id !== detachTarget.id)
        : [];

    async function handleGenerateInvite() {
        setLoading(true);
        try {
            const response = await fetch('/admin/team/invite', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ role: inviteRole }),
            });

            const data = await response.json();

            if (response.ok) {
                setInviteLink(data.link);
            } else {
                toast.error(data.error || 'Не удалось сгенерировать ссылку');
            }
        } catch {
            toast.error('Ошибка сети. Попробуйте ещё раз.');
        } finally {
            setLoading(false);
        }
    }

    function handleCopyLink() {
        navigator.clipboard.writeText(inviteLink).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    function handleOpenInvite() {
        setInviteLink('');
        setCopied(false);
        setInviteRole('master');
        setInviteDialogOpen(true);
    }

    function handleOpenDetach(master: Master) {
        setDetachTarget(master);
        setDetachTargetId('');
    }

    async function handleToggleBookable(master: Master, value: boolean) {
        try {
            const response = await fetch('/admin/team/bookable', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ is_bookable: value }),
            });

            if (response.ok) {
                router.reload({ only: ['masters'] });
            } else {
                toast.error('Не удалось изменить настройку');
            }
        } catch {
            toast.error('Ошибка сети. Попробуйте ещё раз.');
        }
    }

    function handleCloseDetach() {
        setDetachTarget(null);
        setDetachTargetId('');
    }

    function handleDetach() {
        if (!detachTarget) return;

        setDetachLoading(true);

        const payload: Record<string, string> = {};
        if (detachTarget.has_future_appointments && detachTargetId) {
            payload.target_master_id = detachTargetId;
        }

        router.post(`/admin/team/${detachTarget.id}/detach`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                handleCloseDetach();
            },
            onError: (errors) => {
                toast.error(Object.values(errors)[0] as string);
            },
            onFinish: () => {
                setDetachLoading(false);
            },
        });
    }

    const showReceiverSelect = detachTarget?.has_future_appointments && receivers.length > 0;
    const noReceivers = detachTarget?.has_future_appointments && receivers.length === 0;
    const canDetach = detachTarget
        ? (detachTarget.has_future_appointments ? detachTargetId !== '' : true)
        : false;

    return (
        <>
            <Head title="Команда — Вовремя" />

            <AdminLayout title="Команда" auth={auth}>
                <div className="mx-auto max-w-4xl space-y-6">
                    {/* ─── Header ─── */}
                    <div className="flex items-center justify-between">
                        <div>
                            {hasLimit && (
                                <div className="inline-flex items-center gap-2 rounded-xl border border-slate-200/60 bg-white/50 px-4 py-2 backdrop-blur-md dark:border-zinc-700/50 dark:bg-zinc-900/50">
                                    <Users className="size-4 text-blue-500 dark:text-blue-400" />
                                    <span className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                        {currentCount}
                                        <span className="text-slate-400 dark:text-zinc-500"> / {max_masters}</span>
                                    </span>
                                    <span className="text-xs text-slate-400 dark:text-zinc-500">мастеров</span>
                                </div>
                            )}
                        </div>

                        {currentCount > 0 && (
                            <Button
                                onClick={handleOpenInvite}
                                disabled={isLimitReached}
                                className="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition-all hover:from-blue-700 hover:to-indigo-700 hover:shadow-xl disabled:opacity-50 disabled:shadow-none"
                            >
                                <UserPlus className="size-4" />
                                Пригласить мастера
                            </Button>
                        )}
                    </div>

                    {/* ─── Content ─── */}
                    {currentCount === 0 ? (
                        <EmptyState onOpenInvite={handleOpenInvite} />
                    ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {masters.map((master) => (
                                <MasterCard
                                    key={master.id}
                                    master={master}
                                    canManageTeam={can_manage_team}
                                    onDetach={handleOpenDetach}
                                    onToggleBookable={handleToggleBookable}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </AdminLayout>

            {/* ─── Invite Dialog ─── */}
            <Dialog open={inviteDialogOpen} onOpenChange={setInviteDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Пригласить мастера</DialogTitle>
                    </DialogHeader>

                    {!inviteLink ? (
                        <div className="py-4 text-center">
                            {can_invite_admins && (
                                <div className="mb-6">
                                    <p className="mb-3 text-sm font-medium text-slate-700 dark:text-zinc-300">
                                        Кого приглашаем?
                                    </p>
                                    <div className="flex gap-3 justify-center">
                                        <button
                                            type="button"
                                            onClick={() => setInviteRole('master')}
                                            className={`flex flex-col items-center gap-1.5 rounded-xl border-2 px-5 py-3 text-sm font-medium transition-all ${
                                                inviteRole === 'master'
                                                    ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950/30 dark:text-blue-400'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-600'
                                            }`}
                                        >
                                            <Users className="size-5" />
                                            Мастер
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setInviteRole('admin')}
                                            className={`flex flex-col items-center gap-1.5 rounded-xl border-2 px-5 py-3 text-sm font-medium transition-all ${
                                                inviteRole === 'admin'
                                                    ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950/30 dark:text-blue-700 dark:text-blue-400'
                                                    : 'border-slate-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-300'
                                            }`}
                                        >
                                            <Shield className="size-5" />
                                            Администратор
                                        </button>
                                    </div>
                                </div>
                            )}
                            <p className="mb-6 text-sm text-slate-500 dark:text-zinc-400">
                                Нажмите кнопку, чтобы сгенерировать персональную ссылку-приглашение.
                                Ссылка действует 24 часа.
                            </p>
                            <Button
                                onClick={handleGenerateInvite}
                                disabled={loading}
                                className="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition-all hover:from-blue-700 hover:to-indigo-700 hover:shadow-xl disabled:opacity-50"
                            >
                                {loading ? (
                                    <span className="flex items-center gap-2">
                                        <span className="size-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                        Генерация...
                                    </span>
                                ) : (
                                    <>
                                        <Sparkles className="size-4" />
                                        Сгенерировать ссылку
                                    </>
                                )}
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <p className="text-sm text-slate-500 dark:text-zinc-400">
                                Отправьте эту ссылку мастеру. При переходе он автоматически присоединится к вашей команде.
                            </p>

                            <div className="flex items-center gap-2">
                                <Input
                                    value={inviteLink}
                                    readOnly
                                    className="flex-1 font-mono text-xs bg-slate-50 dark:bg-zinc-800"
                                />
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={handleCopyLink}
                                    className="shrink-0"
                                >
                                    {copied ? (
                                        <Check className="size-4 text-emerald-500" />
                                    ) : (
                                        <Copy className="size-4" />
                                    )}
                                </Button>
                            </div>

                            {copied && (
                                <p className="text-center text-xs text-emerald-500">
                                    Ссылка скопирована!
                                </p>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setInviteDialogOpen(false)}
                        >
                            Закрыть
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ─── Detach Dialog ─── */}
            <Dialog open={detachTarget !== null} onOpenChange={(open) => !open && handleCloseDetach()}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Исключить из команды</DialogTitle>
                    </DialogHeader>

                    {detachTarget && (
                        <div className="space-y-4">
                            <p className="text-sm text-slate-500 dark:text-zinc-400">
                                Мастер <strong className="text-slate-900 dark:text-zinc-100">{detachTarget.name}</strong> будет отвязан от студии.
                            </p>

                            {detachTarget.has_future_appointments && receivers.length > 0 && (
                                <>
                                    <p className="text-sm text-slate-500 dark:text-zinc-400">
                                        У мастера есть активные будущие записи. Выберите, кому их передать:
                                    </p>
                                    <Select value={detachTargetId} onValueChange={setDetachTargetId}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Выберите мастера" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {receivers.map((r) => (
                                                <SelectItem key={r.id} value={r.id}>
                                                    {r.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </>
                            )}

                            {detachTarget.has_future_appointments && noReceivers && (
                                <p className="text-sm text-amber-600 dark:text-amber-400">
                                    Нет мастеров для передачи записей. Сначала добавьте мастера.
                                </p>
                            )}

                            {!detachTarget.has_future_appointments && (
                                <p className="text-sm text-slate-500 dark:text-zinc-400">
                                    Активных записей для передачи нет.
                                </p>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={handleCloseDetach}>
                            Отмена
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={!canDetach || detachLoading}
                            onClick={handleDetach}
                        >
                            {detachLoading ? 'Исключение...' : 'Исключить'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
