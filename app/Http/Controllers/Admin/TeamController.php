<?php

namespace App\Http\Controllers\Admin;

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

        $maxMasters = $workspace->activeSubscription()?->tariffPlan?->max_masters;
        $masters = $workspace->users()->where('role', 'master')->get();

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

        $subscription = $workspace->activeSubscription();
        $maxMasters = $subscription?->tariffPlan?->max_masters;

        if ($maxMasters !== null) {
            $currentMasters = $workspace->users()->where('role', 'master')->count();

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

        $link = "https://t.me/se13570350_bot?start=inv_{$token}";

        return response()->json(['link' => $link]);
    }
}
