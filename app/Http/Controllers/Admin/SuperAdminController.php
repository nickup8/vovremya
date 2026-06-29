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
        // TODO: учесть refunded в revenue

        $uniquePayers = Subscription::where('status', SubscriptionStatus::Active)
            ->distinct('user_id')
            ->count('user_id');
        $ltv = $uniquePayers > 0 ? round($totalRevenue / $uniquePayers, 2) : 0;

        $usersByTariff = User::select('tariff', DB::raw('count(*) as count'))
            ->groupBy('tariff')
            ->pluck('count', 'tariff')
            ->toArray();

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
        $query = User::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($tariff = $request->query('tariff')) {
            $query->where('tariff', $tariff);
        }

        if ($request->has('is_blocked')) {
            $query->where('is_blocked', $request->boolean('is_blocked'));
        }

        $users = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

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

        if ($user->expires_at && $user->expires_at->isFuture()) {
            $newExpiry = $user->expires_at->addDays($days);
        } else {
            $newExpiry = now()->addDays($days);
        }

        $user->update([
            'expires_at' => $newExpiry,
            'tariff' => $user->tariff === 'free' ? 'pro' : $user->tariff,
        ]);

        Log::info('Super admin extended subscription', [
            'admin_id' => auth()->id(),
            'user_id' => $user->id,
            'days_added' => $days,
            'new_expires_at' => $newExpiry->toDateTimeString(),
        ]);

        return back()->with('success', "Подписка {$user->name} продлена на {$days} дней.");
    }

    public function impersonate(User $user): RedirectResponse
    {
        Auth::loginUsingId($user->id);

        Log::info('Super admin impersonated user', [
            'admin_id' => session('original_admin_id', auth()->id()),
            'impersonated_user_id' => $user->id,
        ]);

        session(['original_admin_id' => auth()->id()]);

        return redirect()->route('admin.calendar');
    }
}
