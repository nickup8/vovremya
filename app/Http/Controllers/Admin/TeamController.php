<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Models\WorkspaceInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->workspace;

        if (! $workspace) {
            abort(404);
        }

        if (! $user->role->canManageTeam()) {
            abort(403, 'У вас нет прав для управления командой.');
        }

        $maxMasters = $workspace->activeSubscription()?->tariffPlan?->max_masters;
        $masters = $workspace->users()
            ->where('is_master', true)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_url' => $m->avatar_url,
                'telegram_id' => $m->telegram_id,
                'max_id' => $m->max_id,
                'is_owner' => $m->id === $workspace->owner_id,
                'is_current_user' => $m->id === $user->id,
            ]);

        return Inertia::render('admin/team', [
            'masters' => $masters,
            'max_masters' => $maxMasters,
            'can_manage_team' => $user->role->canManageTeam(),
        ]);
    }

    public function generateInvite(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->workspace;

        if (! $workspace) {
            return response()->json(['error' => 'Workspace не найден'], 404);
        }

        if (! $user->role->canManageTeam()) {
            abort(403, 'У вас нет прав для управления командой.');
        }

        $subscription = $workspace->activeSubscription();
        $maxMasters = $subscription?->tariffPlan?->max_masters;

        if ($maxMasters !== null) {
            $currentMasters = $workspace->users()->where('is_master', true)->count();

            if ($currentMasters >= $maxMasters) {
                return response()->json(['error' => 'Достигнут лимит мастеров для вашего тарифа'], 403);
            }
        }

        $token = Str::random(12);

        WorkspaceInvite::create([
            'workspace_id' => $workspace->id,
            'token' => $token,
            'expires_at' => now()->addHours(24),
        ]);

        $link = route('team.invite.page', ['token' => $token]);

        return response()->json(['link' => $link]);
    }

    public function showInvitePage(Request $request): Response
    {
        $token = $request->query('token');

        $invite = WorkspaceInvite::with('workspace')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (! $invite) {
            return Inertia::render('invite/invalid');
        }

        return Inertia::render('invite/show', [
            'token' => $token,
            'workspaceName' => $invite->workspace->name,
            'tgBot' => config('services.telegram.bot_name'),
            'maxBot' => config('services.max.bot_name'),
        ]);
    }

    public function detach(Request $request, string $master): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->role->canManageTeam(), 403, 'У вас нет прав для управления командой.');

        $removed = User::findOrFail($master);
        $user = $request->user();

        abort_unless($removed->workspace_id === $user->workspace_id, 403, 'Мастер не состоит в вашей студии.');
        abort_unless($removed->id !== $user->workspace->owner_id, 422, 'Нельзя исключить владельца студии.');
        abort_unless($removed->id !== $user->id, 422, 'Нельзя исключить себя.');

        $hasFutureAppointments = Appointment::where('master_id', $removed->id)
            ->where('start_time', '>', now())
            ->whereIn('status', ['booked', 'pending_payment', 'prepaid'])
            ->exists();

        $target = null;

        if ($hasFutureAppointments) {
            $targetMasterId = $request->validate([
                'target_master_id' => 'required|uuid|exists:users,id',
            ])['target_master_id'];

            $target = User::findOrFail($targetMasterId);

            abort_unless($target->workspace_id === $user->workspace_id, 422, 'Выбранный мастер не состоит в вашей студии.');
            abort_unless($target->is_master, 422, 'Выбранный пользователь не является мастером.');
            abort_unless($target->id !== $removed->id, 422, 'Нельзя выбрать того же мастера.');
        }

        DB::transaction(function () use ($removed, $target) {
            if ($target) {
                Appointment::where('master_id', $removed->id)
                    ->where('start_time', '>', now())
                    ->whereIn('status', ['booked', 'pending_payment', 'prepaid'])
                    ->update(['master_id' => $target->id]);
            }

            $removed->workspace_id = null;
            $removed->role = UserRole::Owner;
            $removed->save();
        });

        return back()->with('success', "Мастер {$removed->name} исключён из студии.");
    }
}
