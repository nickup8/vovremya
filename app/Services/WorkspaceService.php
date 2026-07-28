<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    /**
     * Создаёт workspace для пользователя с гарантированно уникальным именем.
     */
    public function createForUser(User $user): Workspace
    {
        return DB::transaction(function () use ($user) {
            return $this->createWorkspaceForUser($user);
        });
    }

    /**
     * Создаёт личный workspace мастерам-сотрудникам при истечении подписки студии.
     * Владелец остаётся в своём workspace. Каждому сотруднику создаётся личный workspace.
     * После успешного распада шлёт уведомления всем затронутым пользователям.
     */
    public function dissolveStudio(Workspace $workspace, MasterNotificationService $notificationService): void
    {
        $owner = $workspace->owner;

        $masters = $workspace->users()
            ->where('role', UserRole::Master)
            ->where('id', '!=', $owner->id)
            ->get();

        if ($masters->isNotEmpty()) {
            DB::transaction(function () use ($masters) {
                foreach ($masters as $master) {
                    $this->createWorkspaceForUser($master);
                }
            });
        }

        $message = 'Подписка студии истекла. Вы работаете как одиночка на тарифе Старт.';

        $notificationService->sendToMaster($owner, $message);

        foreach ($masters as $master) {
            $notificationService->sendToMaster($master, $message);
        }
    }

    private function createWorkspaceForUser(User $user): Workspace
    {
        $baseName = $user->name !== '' && $user->name !== null
            ? 'Студия '.$user->name
            : 'Workspace';

        $name = Str::slug($baseName);
        if ($name === '') {
            $name = 'workspace';
        }

        $attempts = 0;
        while (Workspace::where('name', $name)->lockForUpdate()->exists()) {
            $attempts++;
            if ($attempts > 5) {
                $name = 'workspace-'.Str::lower(Str::random(6));
            } else {
                $name = Str::slug($baseName).'-'.Str::lower(Str::random(5));
            }
        }

        $workspace = Workspace::create([
            'name' => $name,
            'owner_id' => $user->id,
        ]);

        $workspace->ensureSlug();

        $user->workspace_id = $workspace->id;
        $user->role = UserRole::Owner;
        $user->save();

        return $workspace;
    }
}
