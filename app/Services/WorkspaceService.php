<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
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
            $baseName = $user->name !== '' && $user->name !== null
                ? 'Студия ' . $user->name
                : 'Workspace';

            $name = Str::slug($baseName);
            if ($name === '') {
                $name = 'workspace';
            }

            $attempts = 0;
            while (Workspace::where('name', $name)->lockForUpdate()->exists()) {
                $attempts++;
                if ($attempts > 5) {
                    $name = 'workspace-' . Str::lower(Str::random(6));
                } else {
                    $name = Str::slug($baseName) . '-' . Str::lower(Str::random(5));
                }
            }

            $workspace = Workspace::create([
                'name' => $name,
                'owner_id' => $user->id,
            ]);

            $user->update([
                'workspace_id' => $workspace->id,
                'role' => 'owner',
            ]);

            return $workspace;
        });
    }
}
