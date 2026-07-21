<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
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
        $activeSubscriptions = Subscription::where('status', SubscriptionStatus::Active)
            ->where('expires_at', '>', now())
            ->get();

        $mrr = (float) $activeSubscriptions->sum(fn (Subscription $s) => $s->period_months > 0
            ? $s->amount_paid / $s->period_months
            : 0);

        $arr = $mrr * 12;

        $totalRevenue = (float) Subscription::where('status', SubscriptionStatus::Active)
            ->sum('amount_paid');

        $uniquePayers = Subscription::where('status', SubscriptionStatus::Active)
            ->distinct('workspace_id')
            ->count('workspace_id');
        $ltv = $uniquePayers > 0 ? round($totalRevenue / $uniquePayers, 2) : 0;

        $usersByTariff = User::join('workspaces', 'users.workspace_id', '=', 'workspaces.id')
            ->join('subscriptions', 'workspaces.id', '=', 'subscriptions.workspace_id')
            ->join('tariff_plans', 'subscriptions.tariff_plan_id', '=', 'tariff_plans.id')
            ->where('subscriptions.status', SubscriptionStatus::Active)
            ->where('subscriptions.expires_at', '>', now())
            ->select('tariff_plans.code as tariff', DB::raw('count(distinct users.id) as count'))
            ->groupBy('tariff_plans.code')
            ->pluck('count', 'tariff')
            ->toArray();

        // Users without active subscription are "start" tier
        $startCount = User::whereDoesntHave('workspace.subscriptions', function ($q) {
            $q->where('status', SubscriptionStatus::Active)
                ->where('expires_at', '>', now());
        })->count();

        if ($startCount > 0) {
            $usersByTariff['start'] = ($usersByTariff['start'] ?? 0) + $startCount;
        }

        $totalUsers = User::count();

        $activeCount = $activeSubscriptions->count();

        return Inertia::render('SuperAdmin/Dashboard', [
            'mrr' => $mrr,
            'arr' => $arr,
            'ltv' => $ltv,
            'users_by_tariff' => $usersByTariff,
            'total_users' => $totalUsers,
            'active_subscriptions' => $activeCount,
        ]);
    }

    public function users(Request $request): Response
    {
        $query = User::query()
            ->with(['workspace.subscriptions.tariffPlan']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
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
        $user->update(['is_blocked' => ! $user->is_blocked]);

        Log::info('Super admin blocked/unblocked user', [
            'admin_id' => auth()->id(),
            'user_id' => $user->id,
            'is_blocked' => $user->is_blocked,
        ]);

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

        if ($activeSubscription && $activeSubscription->expires_at && $activeSubscription->expires_at->isFuture()) {
            $newExpiry = $activeSubscription->expires_at->addDays($days);
        } else {
            $newExpiry = now()->addDays($days);

            // Create a new subscription if none exists
            if (! $activeSubscription) {
                $startPlan = \App\Models\TariffPlan::where('code', 'pro')->first();

                if ($startPlan) {
                    $workspace->subscriptions()->create([
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

        return back()->with('success', "Подписка {$user->name} продлена на {$days} дней.");
    }

    public function impersonate(User $user): RedirectResponse
    {
        if ($user->is_super_admin) {
            abort(403, 'Нельзя зайти под другим суперадмином.');
        }

        $originalAdminId = auth()->id();

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

        Auth::loginUsingId($originalAdminId);

        session()->forget('original_admin_id');

        Log::info('Super admin left impersonation', [
            'admin_id' => $originalAdminId,
        ]);

        return redirect()->route('super_admin.dashboard');
    }
}
