<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ScheduleController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = auth()->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        if ($isAdminOrOwner && $request->query('master_id')) {
            $targetMaster = User::where('id', $request->query('master_id'))
                ->where('workspace_id', $user->workspace_id)
                ->where('is_master', true)
                ->firstOrFail();
        } else {
            $targetMaster = $user;
        }

        return Inertia::render('admin/schedule', [
            'workingHours' => $targetMaster->workingHours()->get(),
            'blockedTimes' => $targetMaster->blockedTimes()->get(),
            'profile' => [
                'id' => $targetMaster->id,
                'slot_interval' => $targetMaster->slot_interval,
                'timezone' => $targetMaster->getTimezone(),
            ],
        ]);
    }
}
