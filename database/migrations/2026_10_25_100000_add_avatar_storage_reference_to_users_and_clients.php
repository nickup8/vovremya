<?php

use App\Services\Avatars\AvatarPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable-ссылки на хранилище аватара (принадлежащий приложению
     * диск + путь). Старые записи заполняются из /storage/...-URL,
     * записи с внешними URL остаются NULL.
     */
    public function up(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('avatar_disk')->nullable()->after('avatar_url');
                $blueprint->string('avatar_path')->nullable()->after('avatar_disk');
            });

            foreach (DB::table($table)->whereNotNull('avatar_url')->get(['id', 'avatar_url']) as $row) {
                $path = AvatarPath::fromLegacyUrl((string) $row->avatar_url);

                if ($path === null || ! AvatarPath::isAllowed($path)) {
                    continue;
                }

                DB::table($table)
                    ->where('id', $row->id)
                    ->whereNull('avatar_disk')
                    ->update([
                        'avatar_disk' => 'public',
                        'avatar_path' => $path,
                    ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['avatar_disk', 'avatar_path']);
            });
        }
    }
};
