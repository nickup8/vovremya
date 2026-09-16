<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Subscription;
use App\Models\SuperAdminAuditLog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $activeSubscription = $workspace->activeSubscription();

        $before = $activeSubscription
            ? ['expires_at' => $activeSubscription->expires_at?->toDateTimeString()]
            : [];

        if ($activeSubscription && $activeSubscription->expires_at && $activeSubscription->expires_at->isFuture()) {
            $newExpiry = $activeSubscription->expires_at->addDays($days);
        } else {
            $newExpiry = now()->addDays($days);

            // Create a new subscription if none exists
            if (! $activeSubscription) {
                $startPlan = TariffPlan::where('code', 'pro')->first();

                if ($startPlan) {
                    $activeSubscription = $workspace->subscriptions()->create([
                        'tariff_plan_id' => $startPlan->id,
                        'period_months' => 1,
                        'amount_paid' => 0,
                        'status' => SubscriptionStatus::Active->value,
                        'starts_at' => now(),
                        'expires_at' => $newExpiry,
                    ]);
                }
            }
        }

        if ($activeSubscription) {
            $activeSubscription->update(['expires_at' => $newExpiry]);
        }

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

        return redirect()->route('super_admin.dashboard');
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
}
