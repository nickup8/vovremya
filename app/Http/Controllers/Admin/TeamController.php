<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $masters = $workspace->users()->where('is_master', true)->get();

        return Inertia::render('admin/team', [
            'masters' => $masters,
            'max_masters' => $maxMasters,
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
}
