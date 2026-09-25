<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // All existing platform_admin_access records that have notifications.send
        // should also have notifications.view — idempotent/safe.
        $accesses = DB::table('platform_admin_accesses')
            ->whereJsonContains('permissions', 'notifications.send')
            ->get();

        foreach ($accesses as $access) {
            $permissions = json_decode($access->permissions, true) ?? [];

            if (! in_array('notifications.view', $permissions, true)) {
                $permissions[] = 'notifications.view';

                DB::table('platform_admin_accesses')
                    ->where('id', $access->id)
                    ->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        // Remove notifications.view from records that ONLY got it via this backfill.
        // Since we can't know which were added by this migration, we leave them.
    }
};
