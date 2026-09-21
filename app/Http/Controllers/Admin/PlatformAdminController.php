<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdminAccess;
use App\Models\User;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $roots = User::where('is_super_admin', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        $accesses = PlatformAdminAccess::with(['user', 'grantedBy'])
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('SuperAdmin/Admins', [
            'roots' => $roots,
            'accesses' => $accesses,
            'allPermissions' => array_map(fn (PlatformPermission $p) => [
                'value' => $p->value,
                'label' => self::permissionLabel($p),
            ], PlatformPermission::cases()),
        ]);
    }

    public function searchUsers(Request $request)
    {
        $q = $request->query('q', '');

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $safe = '%'.addcslashes($q, '%_').'%';

        $existingIds = PlatformAdminAccess::pluck('user_id');
        $rootIds = User::where('is_super_admin', true)->pluck('id');

        $users = User::query()
            ->where(function ($query) use ($safe) {
                $query->where('name', 'like', $safe)
                    ->orWhere('phone', 'like', $safe)
                    ->orWhere('email', 'like', $safe);
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'already_admin' => $existingIds->contains($u->id) || $rootIds->contains($u->id),
            ]);

        return response()->json($users);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'permissions' => 'required|array|min:1',
            'permissions.*' => ['string', 'in:'.implode(',', array_column(PlatformPermission::cases(), 'value'))],
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($user->is_super_admin) {
            abort(422, 'ROOT пользователя нельзя назначить limited admin.');
        }

        if (PlatformAdminAccess::where('user_id', $user->id)->exists()) {
            abort(422, 'Доступ для этого пользователя уже назначен.');
        }

        $permissions = $this->normalizePermissions($validated['permissions']);

        $actor = $request->user();

        $access = PlatformAdminAccess::create([
            'user_id' => $user->id,
            'permissions' => $permissions,
            'is_active' => true,
            'granted_by' => $actor->id,
        ]);

        app(SuperAdminAuditLogger::class)->log(
            $actor,
            'platform_admin.access_granted',
            $user,
            [],
            ['permissions' => $permissions, 'is_active' => true],
            ['access_id' => $access->id],
        );

        return back()->with('success', "Администратор {$user->name} назначен.");
    }

    public function update(Request $request, PlatformAdminAccess $access): RedirectResponse
    {
        $actor = $request->user();

        if (! $actor->is_super_admin && $actor->id === $access->user_id) {
            abort(403, 'Нельзя изменять собственные права.');
        }

        $validated = $request->validate([
            'permissions' => 'required|array|min:1',
            'permissions.*' => ['string', 'in:'.implode(',', array_column(PlatformPermission::cases(), 'value'))],
        ]);

        $oldPermissions = $access->permissions;
        $permissions = $this->normalizePermissions($validated['permissions']);

        $access->update(['permissions' => $permissions]);

        app(SuperAdminAuditLogger::class)->log(
            $actor,
            'platform_admin.permissions_changed',
            $access->user,
            ['permissions' => $oldPermissions],
            ['permissions' => $permissions],
            ['access_id' => $access->id],
        );

        return back()->with('success', 'Права администратора обновлены.');
    }

    public function toggleActive(Request $request, PlatformAdminAccess $access): RedirectResponse
    {
        $actor = $request->user();

        if (! $actor->is_super_admin && $actor->id === $access->user_id) {
            abort(403, 'Нельзя изменять собственный статус.');
        }

        $wasActive = $access->is_active;
        $access->update(['is_active' => ! $wasActive]);

        $action = $access->is_active ? 'platform_admin.activated' : 'platform_admin.deactivated';

        app(SuperAdminAuditLogger::class)->log(
            $actor,
            $action,
            $access->user,
            ['is_active' => $wasActive],
            ['is_active' => $access->is_active],
            ['access_id' => $access->id],
        );

        return back()->with('success', $access->is_active
            ? 'Доступ администратора включён.'
            : 'Доступ администратора отключён.');
    }

    private function normalizePermissions(array $permissions): array
    {
        $valid = array_filter($permissions, fn ($p) => PlatformPermission::tryFrom($p) !== null);

        // Dependency: users.block / subscriptions.extend / impersonation.use require users.view
        $needsUsersView = ['users.block', 'subscriptions.extend', 'impersonation.use'];

        if (count(array_intersect($needsUsersView, $valid)) > 0 && ! in_array('users.view', $valid)) {
            $valid[] = 'users.view';
        }

        // Dependency: plans.update requires plans.view
        if (in_array('plans.update', $valid) && ! in_array('plans.view', $valid)) {
            $valid[] = 'plans.view';
        }

        return array_values(array_unique($valid));
    }

    public static function permissionLabel(PlatformPermission $permission): string
    {
        return match ($permission) {
            PlatformPermission::DashboardView => 'Обзор',
            PlatformPermission::UsersView => 'Пользователи',
            PlatformPermission::UsersBlock => 'Блокировка пользователей',
            PlatformPermission::SubscriptionsExtend => 'Продление Pro',
            PlatformPermission::ImpersonationUse => 'Вход от имени пользователя',
            PlatformPermission::PlansView => 'Просмотр тарифов',
            PlatformPermission::PlansUpdate => 'Изменение тарифов',
            PlatformPermission::AuditView => 'Журнал действий',
            PlatformPermission::PlatformAdminsManage => 'Управление администраторами',
            PlatformPermission::NotificationsSend => 'Отправка уведомлений',
        };
    }
}
