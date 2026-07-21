<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')
            ->whereNull('workspace_id')
            ->get();

        foreach ($users as $user) {
            $workspaceId = Str::uuid()->toString();

            DB::table('workspaces')->insert([
                'id' => $workspaceId,
                'name' => "Студия {$user->name}",
                'owner_id' => $user->id,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now(),
            ]);

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'workspace_id' => $workspaceId,
                    'role' => 'owner',
                ]);
        }
    }

    public function down(): void
    {
        // Data migration — no down needed
    }
};
