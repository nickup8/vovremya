<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlatformPermission;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Subscription;
use App\Models\SuperAdminAuditLog;
use App\Models\SystemNotificationMessage;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SystemNotification;
use App\Services\Billing\BillingCoreWriter;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function index(): Response
    {
        // ── Current subscriptions (one per workspace, matching Workspace::activeSubscription) ──

        $now = now()->toDateTimeString();

        $currentSubIds = DB::select("
            SELECT DISTINCT ON (workspace_id) id
            FROM subscriptions
            WHERE status = ? AND expires_at > ?
            ORDER BY workspace_id, expires_at DESC
        ", [SubscriptionStatus::Active->value, $now]);

        $currentSubIds = collect($currentSubIds)->pluck('id');

        $currentSubs = Subscription::whereIn('id', $currentSubIds)->get();

        $mrr = (float) $currentSubs->sum(fn (Subscription $s) => $s->period_months > 0
            ? $s->amount_paid / $s->period_months
            : 0);

        $arr = $mrr * 12;

        // ── Masters / accounts ──

        $totalMasters = User::where('is_master', true)->count();
        $newMasters7d = User::where('is_master', true)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $newMasters30d = User::where('is_master', true)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // ── Workspaces ──

        $totalWorkspaces = Workspace::count();

        // ── Tariffs (by workspace current subscription) ──

        $proPlanId = TariffPlan::where('code', 'pro')->value('id');

        $proCount = $proPlanId
            ? $currentSubs->filter(fn (Subscription $s) => $s->tariff_plan_id === $proPlanId)->count()
            : 0;

        $avgMrrPerPro = $proCount > 0 ? round($mrr / $proCount, 2) : 0;

        $activeCount = $currentSubs->count();

        $startCount = $totalWorkspaces - $activeCount;

        // ── Appointment activity ──

        $appointmentsCreated30d = Appointment::where('created_at', '>=', now()->subDays(30))->count();
        $cancellations30d = Appointment::whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', now()->subDays(30))
            ->count();

        // ── Messenger penetration (identity linkage) ──

        $mastersBase = User::where('is_master', true);

        $telegramLinked = (clone $mastersBase)->whereNotNull('telegram_id')->count();
        $maxLinked = (clone $mastersBase)->whereNotNull('max_id')->count();
        $vkLinked = (clone $mastersBase)->whereNotNull('vk_id')->count();

        // ── Tariff distribution (users by current subscription) ──

        $usersByTariff = User::query()
            ->join('workspaces', 'users.workspace_id', '=', 'workspaces.id')
            ->join('subscriptions', function ($join) use ($currentSubIds) {
                $join->on('subscriptions.workspace_id', '=', 'workspaces.id')
                    ->whereIn('subscriptions.id', $currentSubIds);
            })
            ->join('tariff_plans', 'subscriptions.tariff_plan_id', '=', 'tariff_plans.id')
            ->where('subscriptions.status', SubscriptionStatus::Active)
            ->select('tariff_plans.code as tariff', DB::raw('count(distinct users.id) as count'))
            ->groupBy('tariff_plans.code')
            ->pluck('count', 'tariff')
            ->toArray();

        $startUsers = User::whereDoesntHave('workspace', function ($q) use ($currentSubIds) {
            $q->whereIn('id', function ($sub) use ($currentSubIds) {
                $sub->select('workspace_id')
                    ->from('subscriptions')
                    ->whereIn('id', $currentSubIds);
            });
        })->count();

        if ($startUsers > 0) {
            $usersByTariff['start'] = ($usersByTariff['start'] ?? 0) + $startUsers;
        }

        $totalUsers = User::count();

        return Inertia::render('SuperAdmin/Dashboard', [
            'mrr' => $mrr,
            'arr' => $arr,
            'avg_mrr_per_pro' => $avgMrrPerPro,
            'users_by_tariff' => $usersByTariff,
            'total_users' => $totalUsers,
            'active_subscriptions' => $activeCount,
            'total_masters' => $totalMasters,
            'new_masters_7d' => $newMasters7d,
            'new_masters_30d' => $newMasters30d,
            'total_workspaces' => $totalWorkspaces,
            'start_count' => $startCount,
            'pro_count' => $proCount,
            'appointments_30d' => $appointmentsCreated30d,
            'cancellations_30d' => $cancellations30d,
            'telegram_linked' => $telegramLinked,
            'max_linked' => $maxLinked,
            'vk_linked' => $vkLinked,
        ]);
    }

    public function users(Request $request): Response
    {
        $query = User::query()
            ->with(['workspace.subscriptions.tariffPlan']);

        if ($search = $request->query('search')) {
            $safe = '%'.addcslashes($search, '%_').'%';
            $query->where(function ($q) use ($safe) {
                $q->where('name', 'like', $safe)
                    ->orWhere('phone', 'like', $safe)
                    ->orWhere('email', 'like', $safe);
            });
        }

        if ($tariff = $request->query('tariff')) {
            $query->whereHas('workspace.subscriptions', function ($q) use ($tariff) {
                $q->where('status', SubscriptionStatus::Active)
                    ->where('expires_at', '>', now())
                    ->whereHas('tariffPlan', function ($q2) use ($tariff) {
                        $q2->where('code', $tariff);
                    });
            });
        }

        if ($request->has('is_blocked')) {
            $query->where('is_blocked', $request->boolean('is_blocked'));
        }

        $users = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // Append virtual 'tariff' attribute for frontend compatibility
        $users->getCollection()->transform(function ($user) {
            $user->tariff = $user->workspace?->activeSubscription()?->tariffPlan?->code ?? 'start';

            return $user;
        });

        return Inertia::render('SuperAdmin/Users', [
            'users' => $users,
            'filters' => $request->only(['search', 'tariff', 'is_blocked']),
        ]);
    }

    public function blockUser(User $user): RedirectResponse
    {
        $actor = auth()->user();

        // A. Cannot block/unblock yourself
        if ($actor->id === $user->id) {
            abort(403, 'Нельзя изменить блокировку собственного аккаунта.');
        }

        // B. Limited admin cannot block/unblock ROOT
        if (! $actor->is_super_admin && $user->is_super_admin) {
            abort(403, 'Недостаточно прав для изменения ROOT-пользователя.');
        }

        $wasBlocked = $user->is_blocked;
        $user->is_blocked = ! $wasBlocked;
        $user->save();

        // При блокировке — уничтожить активные сессии забаненного (мгновенный вылет)
        if (! $wasBlocked && $user->is_blocked) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        Log::info('Super admin blocked/unblocked user', [
            'admin_id' => auth()->id(),
            'user_id' => $user->id,
            'is_blocked' => $user->is_blocked,
        ]);

        app(SuperAdminAuditLogger::class)->log(
            auth()->user(),
            $user->is_blocked ? 'user.blocked' : 'user.unblocked',
            $user,
            ['is_blocked' => $wasBlocked],
            ['is_blocked' => $user->is_blocked],
        );

        return back()->with('success', $user->is_blocked
            ? "Пользователь {$user->name} заблокирован."
            : "Пользователь {$user->name} разблокирован."
        );
    }

    public function extendSubscription(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $days = $validated['days'];

        $workspace = $user->workspace;

        if (! $workspace) {
            abort(422, 'У пользователя нет рабочего пространства.');
        }

        $coreWriter = app(BillingCoreWriter::class);

        // Весь legacy + core write — в одной транзакции
        $result = DB::transaction(function () use ($workspace, $days, $coreWriter) {
            // Workspace lockForUpdate для сериализации конкурентных extend
            $lockedWorkspace = Workspace::where('id', $workspace->id)->lockForUpdate()->first();

            // Заново читаем active subscription после lock (не stale)
            $activeSubscription = $lockedWorkspace->activeSubscription();

            $before = $activeSubscription
                ? ['expires_at' => $activeSubscription->expires_at?->toDateTimeString()]
                : [];

            $proPlan = TariffPlan::where('code', 'pro')->first();

            if ($activeSubscription && $activeSubscription->expires_at && $activeSubscription->expires_at->isFuture()) {
                // ── Extend existing ──
                $oldExpiry = $activeSubscription->expires_at->copy();
                $newExpiry = $oldExpiry->addDays($days);

                $activeSubscription->update(['expires_at' => $newExpiry]);

                // Core mirror: delta cycle [oldExpiry, newExpiry]
                if ($proPlan) {
                    $coreWriter->adminGrant(
                        $workspace->id,
                        $proPlan,
                        $activeSubscription,
                        $oldExpiry->toDateTimeString(),
                        $newExpiry->toDateTimeString(),
                    );
                }
            } else {
                // ── New grant ──
                $newExpiry = now()->addDays($days);

                if (! $activeSubscription && $proPlan) {
                    $activeSubscription = $lockedWorkspace->subscriptions()->create([
                        'tariff_plan_id' => $proPlan->id,
                        'period_months' => 1,
                        'amount_paid' => 0,
                        'status' => SubscriptionStatus::Active->value,
                        'starts_at' => now(),
                        'expires_at' => $newExpiry,
                    ]);
                } elseif ($activeSubscription) {
                    $activeSubscription->update(['expires_at' => $newExpiry]);
                }

                // Core mirror: full cycle [starts_at, expires_at]
                if ($activeSubscription && $proPlan) {
                    $coreWriter->adminGrant(
                        $workspace->id,
                        $proPlan,
                        $activeSubscription,
                        $activeSubscription->fresh()->starts_at->toDateTimeString(),
                        $newExpiry->toDateTimeString(),
                    );
                }
            }

            return ['before' => $before, 'newExpiry' => $newExpiry, 'activeSubscription' => $activeSubscription];
        });

        $before = $result['before'];
        $newExpiry = $result['newExpiry'];
        $activeSubscription = $result['activeSubscription'];

        Log::info('Super admin extended subscription', [
            'admin_id' => auth()->id(),
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'days_added' => $days,
            'new_expires_at' => $newExpiry->toDateTimeString(),
        ]);

        if ($activeSubscription) {
            app(SuperAdminAuditLogger::class)->log(
                auth()->user(),
                'subscription.extended',
                $activeSubscription,
                $before,
                ['expires_at' => $newExpiry->toDateTimeString()],
                ['days_added' => $days, 'workspace_id' => $workspace->id],
            );
        }

        return back()->with('success', "Подписка {$user->name} продлена на {$days} дней.");
    }

    public function impersonate(User $user): RedirectResponse
    {
        if ($user->is_super_admin) {
            abort(403, 'Нельзя зайти под другим суперадмином.');
        }

        $originalAdminId = auth()->id();

        app(SuperAdminAuditLogger::class)->log(
            auth()->user(),
            'impersonation.started',
            $user,
            metadata: ['target_user_id' => $user->id],
        );

        Auth::loginUsingId($user->id);

        session(['original_admin_id' => $originalAdminId]);

        Log::info('Super admin impersonated user', [
            'admin_id' => $originalAdminId,
            'impersonated_user_id' => $user->id,
        ]);

        return redirect()->route('admin.calendar');
    }

    public function leaveImpersonate(): RedirectResponse
    {
        $originalAdminId = session('original_admin_id');

        if (! $originalAdminId) {
            abort(403, 'Нет активной сессии подмены.');
        }

        $originalAdmin = User::find($originalAdminId);
        $impersonatedUserId = auth()->id();

        Auth::loginUsingId($originalAdminId);

        session()->forget('original_admin_id');

        Log::info('Super admin left impersonation', [
            'admin_id' => $originalAdminId,
        ]);

        if ($originalAdmin) {
            app(SuperAdminAuditLogger::class)->log(
                $originalAdmin,
                'impersonation.ended',
                metadata: ['impersonated_user_id' => $impersonatedUserId],
            );
        }

        return $this->redirectForPlatformAdmin($originalAdmin);
    }

    private function redirectForPlatformAdmin(User $user): RedirectResponse
    {
        if ($user->is_super_admin) {
            return redirect()->route('super_admin.dashboard');
        }

        $access = $user->platformAdminAccess;

        if (! $access || ! $access->is_active) {
            abort(403, 'Нет доступных разделов.');
        }

        $readRoutes = [
            [PlatformPermission::DashboardView, 'super_admin.dashboard'],
            [PlatformPermission::UsersView, 'super_admin.users'],
            [PlatformPermission::PlansView, 'super_admin.plans'],
            [PlatformPermission::AuditView, 'super_admin.audit'],
            [PlatformPermission::PlatformAdminsManage, 'super_admin.admins'],
        ];

        foreach ($readRoutes as [$permission, $route]) {
            if ($access->hasPermission($permission)) {
                return redirect()->route($route);
            }
        }

        abort(403, 'Нет доступных разделов.');
    }

    public function plans(): Response
    {
        $plans = TariffPlan::orderBy('price_monthly')->get();

        return Inertia::render('SuperAdmin/Plans', [
            'plans' => $plans,
        ]);
    }

    public function updatePlan(Request $request, TariffPlan $plan): RedirectResponse
    {
        if ($plan->code === 'start') {
            $validated = $request->validate([
                'max_appointments_per_month' => 'required|integer|min:1',
            ]);
        } elseif ($plan->code === 'pro') {
            $validated = $request->validate([
                'price_monthly' => 'required|integer|min:0',
            ]);
        } else {
            abort(422, 'Изменение этого тарифа не поддерживается.');
        }

        $before = [];
        foreach ($validated as $key => $value) {
            $before[$key] = $plan->$key;
        }

        $plan->update($validated);

        Log::info('Super admin updated plan', [
            'admin_id' => auth()->id(),
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'fields' => array_keys($validated),
        ]);

        $action = $plan->code === 'start' ? 'plan.start_limit_updated' : 'plan.pro_price_updated';

        app(SuperAdminAuditLogger::class)->log(
            auth()->user(),
            $action,
            $plan,
            $before,
            $validated,
        );

        return back()->with('success', "Тариф «{$plan->name}» обновлён.");
    }

    public function audit(Request $request): Response
    {
        $query = SuperAdminAuditLog::query()
            ->with('superAdmin')
            ->orderByDesc('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($adminId = $request->query('super_admin')) {
            $query->where('super_admin_id', $adminId);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        $logs = $query->paginate(50)->withQueryString();

        $actions = [
            'user.blocked',
            'user.unblocked',
            'subscription.extended',
            'impersonation.started',
            'impersonation.ended',
            'plan.start_limit_updated',
            'plan.pro_price_updated',
            'platform_admin.access_granted',
            'platform_admin.permissions_changed',
            'platform_admin.activated',
            'platform_admin.deactivated',
            'notification.sent',
            'notification.updated',
            'notification.deleted',
        ];

        $admins = User::query()
            ->where('is_super_admin', true)
            ->orWhereHas('platformAdminAccess')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('SuperAdmin/Audit', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'super_admin', 'date_from', 'date_to']),
            'actions' => $actions,
            'admins' => $admins,
        ]);
    }

    public function sendNotification(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_type' => 'required|in:all,user',
            'user_id' => 'required_if:recipient_type,user',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $title = $validated['title'];
        $body = $validated['body'];
        $admin = auth()->user();

        if ($validated['recipient_type'] === 'user') {
            $user = User::where('is_master', true)
                ->where('is_blocked', false)
                ->findOrFail($validated['user_id']);

            DB::transaction(function () use ($admin, $user, $title, $body) {
                $message = SystemNotificationMessage::create([
                    'title' => $title,
                    'body' => $body,
                    'created_by' => $admin->id,
                ]);

                $user->notify(new SystemNotification($title, $body, $message->id));

                app(SuperAdminAuditLogger::class)->log(
                    $admin,
                    'notification.sent',
                    $message,
                    metadata: [
                        'system_message_id' => $message->id,
                        'recipient_type' => 'user',
                        'recipients_count' => 1,
                        'title' => $title,
                    ],
                );
            });

            return back()->with('success', "Уведомление отправлено пользователю {$user->name}.");
        }

        $recipients = User::query()
            ->where('is_master', true)
            ->where('is_blocked', false)
            ->get();

        DB::transaction(function () use ($admin, $recipients, $title, $body) {
            $message = SystemNotificationMessage::create([
                'title' => $title,
                'body' => $body,
                'created_by' => $admin->id,
            ]);

            foreach ($recipients as $user) {
                $user->notify(new SystemNotification($title, $body, $message->id));
            }

            app(SuperAdminAuditLogger::class)->log(
                $admin,
                'notification.sent',
                $message,
                metadata: [
                    'system_message_id' => $message->id,
                    'recipient_type' => 'all',
                    'recipients_count' => $recipients->count(),
                    'title' => $title,
                ],
            );
        });

        return back()->with('success', "Уведомление отправлено {$recipients->count()} мастерам.");
    }

    public function notificationsIndex(Request $request): Response
    {
        $query = SystemNotificationMessage::query()
            ->withCount(['notifications', 'notifications as read_count' => function ($q) {
                $q->whereNotNull('read_at');
            }])
            ->orderByDesc('created_at');

        $messages = $query->paginate(15)->withQueryString();

        $recipients = User::query()
            ->where('is_master', true)
            ->where('is_blocked', false)
            ->select('id', 'name', 'phone')
            ->orderBy('name')
            ->get();

        return Inertia::render('SuperAdmin/Notifications', [
            'messages' => $messages,
            'recipients' => $recipients,
        ]);
    }

    public function notificationsUpdate(Request $request, SystemNotificationMessage $message): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        DB::transaction(function () use ($message, $validated) {
            $fresh = SystemNotificationMessage::where('id', $message->id)->lockForUpdate()->first();

            $before = ['title' => $fresh->title, 'body' => $fresh->body];

            $fresh->update([
                'title' => $validated['title'],
                'body' => $validated['body'],
            ]);

            app(SuperAdminAuditLogger::class)->log(
                auth()->user(),
                'notification.updated',
                $fresh,
                $before,
                ['title' => $validated['title'], 'body' => $validated['body']],
                ['system_message_id' => $fresh->id],
            );
        });

        return back()->with('success', 'Сообщение обновлено.');
    }

    public function notificationsDestroy(SystemNotificationMessage $message): RedirectResponse
    {
        DB::transaction(function () use ($message) {
            $fresh = SystemNotificationMessage::where('id', $message->id)->lockForUpdate()->first();

            $stats = [
                'recipients_total' => $fresh->notifications()->count(),
                'read_count' => $fresh->notifications()->whereNotNull('read_at')->count(),
                'unread_count' => $fresh->notifications()->whereNull('read_at')->count(),
            ];

            $fresh->notifications()->forceDelete();
            $fresh->delete();

            app(SuperAdminAuditLogger::class)->log(
                auth()->user(),
                'notification.deleted',
                $fresh,
                metadata: array_merge([
                    'system_message_id' => $fresh->id,
                    'title' => $fresh->title,
                ], $stats),
            );
        });

        return back()->with('success', 'Сообщение удалено.');
    }
}
