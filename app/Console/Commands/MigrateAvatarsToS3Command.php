<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\Avatars\AvatarPath;
use App\Services\Avatars\AvatarStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateAvatarsToS3Command extends Command
{
    protected $signature = 'avatars:migrate-to-s3
        {--dry-run : Показать, что будет перенесено, без записей в БД/S3 и без удалений}';

    protected $description = 'Copy local avatars to the media (S3) disk: size + SHA-256 verification, conditional DB update, local originals kept';

    private int $migrated = 0;

    private int $alreadyMigrated = 0;

    private int $planned = 0;

    private int $foreign = 0;

    private int $conflicts = 0;

    private int $missing = 0;

    private int $mismatches = 0;

    private int $failed = 0;

    public function handle(AvatarStorageService $avatars): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetDisk = $avatars->disk();
        $targetConfig = config("filesystems.disks.{$targetDisk}");

        if (! is_array($targetConfig)) {
            $this->error("Target disk [{$targetDisk}] is not configured.");

            return self::FAILURE;
        }

        $driver = $targetConfig['driver'] ?? null;

        if (! $dryRun) {
            if ($driver === 'local') {
                $targetRoot = rtrim((string) Storage::disk($targetDisk)->path(''), '/\\');
                $sourceRoot = rtrim((string) Storage::disk('public')->path(''), '/\\');

                if ($targetRoot === $sourceRoot) {
                    $this->error('Target media disk is local and shares the public disk root. Set MEDIA_DRIVER=s3 before migrating.');

                    return self::FAILURE;
                }
            }

            if ($driver === 's3' && (! is_string($targetConfig['url'] ?? null) || trim((string) $targetConfig['url']) === '')) {
                $this->error('MEDIA_PUBLIC_URL is not configured; refusing to migrate without a public URL.');

                return self::FAILURE;
            }
        }

        $this->info(sprintf(
            '%s avatars from local storage to [%s] (%s).',
            $dryRun ? 'Dry-run: listing' : 'Migrating',
            $targetDisk,
            (string) $driver
        ));

        foreach ([User::class, Client::class] as $modelClass) {
            /** @var iterable<User|Client> $models */
            $models = $modelClass::query()
                ->whereNotNull('avatar_url')
                ->orderBy('id')
                ->get();

            foreach ($models as $model) {
                $this->process($model, $avatars, $targetDisk, $dryRun);
            }
        }

        return $this->summary($dryRun);
    }

    private function process(Model $model, AvatarStorageService $avatars, string $targetDisk, bool $dryRun): void
    {
        /** @var User|Client $model */
        $originalUrl = (string) $model->getAttribute('avatar_url');
        $observedDisk = $model->getAttribute('avatar_disk');
        $observedPath = $model->getAttribute('avatar_path');

        if ($observedDisk === $targetDisk && is_string($observedPath)) {
            $this->alreadyMigrated++;
            $this->line("  <info>=</info> {$this->label($model)} already on [{$targetDisk}]");

            return;
        }

        $reference = $model->avatarReference();

        if (! $reference['owned'] || $reference['disk'] === null || $reference['path'] === null) {
            $this->foreign++;
            $this->line("  <comment>-</comment> {$this->label($model)} skipped: foreign or non-avatar URL [{$originalUrl}]");

            return;
        }

        $sourceDisk = $reference['disk'];
        $sourcePath = $reference['path'];

        if (! AvatarPath::isAllowed($sourcePath)) {
            $this->foreign++;
            $this->line("  <comment>-</comment> {$this->label($model)} skipped: path [{$sourcePath}] outside allowed avatar prefixes");

            return;
        }

        if ($dryRun) {
            $this->planned++;
            $this->line("  <info>~</info> {$this->label($model)} would copy [{$sourceDisk}:{$sourcePath}] -> [{$targetDisk}:{$this->targetDirectory($sourcePath)}<random>.{$this->extension($sourcePath)}]");

            return;
        }

        try {
            $contents = Storage::disk($sourceDisk)->get($sourcePath);
        } catch (Throwable $e) {
            $this->missing++;
            $this->line("  <error>!</error> {$this->label($model)} local file unreadable [{$sourceDisk}:{$sourcePath}]: {$e->getMessage()}");

            return;
        }

        if (! is_string($contents)) {
            $this->missing++;
            $this->line("  <error>!</error> {$this->label($model)} local file missing [{$sourceDisk}:{$sourcePath}]");

            return;
        }

        $sourceHash = hash('sha256', $contents);
        $targetPath = $this->targetDirectory($sourcePath).Str::random(40).'.'.$this->extension($sourcePath);

        if (! AvatarPath::isAllowed($targetPath)) {
            $this->failed++;
            $this->line("  <error>!</error> {$this->label($model)} computed target path [{$targetPath}] is not allowed");

            return;
        }

        try {
            if (Storage::disk($targetDisk)->exists($targetPath)) {
                $this->conflicts++;
                $this->line("  <error>!</error> {$this->label($model)} target object [{$targetDisk}:{$targetPath}] already exists; refusing to overwrite");

                return;
            }

            $written = Storage::disk($targetDisk)->put($targetPath, $contents);

            if ($written !== true) {
                $this->failed++;
                $this->line("  <error>!</error> {$this->label($model)} S3 write failed [{$targetDisk}:{$targetPath}]");

                return;
            }

            $stored = Storage::disk($targetDisk)->get($targetPath);

            if (! is_string($stored)
                || strlen($stored) !== strlen($contents)
                || hash('sha256', $stored) !== $sourceHash) {
                $this->deleteOwnTarget($targetDisk, $targetPath);
                $this->mismatches++;
                $this->line("  <error>!</error> {$this->label($model)} verification failed for [{$targetDisk}:{$targetPath}] (size/SHA-256 mismatch)");

                return;
            }

            $newUrl = $avatars->url($targetDisk, $targetPath);
        } catch (Throwable $e) {
            $this->deleteOwnTarget($targetDisk, $targetPath);
            $this->failed++;
            $this->line("  <error>!</error> {$this->label($model)} S3 error: {$e->getMessage()}");

            return;
        }

        $affected = $this->conditionalUpdate($model, $originalUrl, $observedDisk, $observedPath, [
            'avatar_url' => $newUrl,
            'avatar_disk' => $targetDisk,
            'avatar_path' => $targetPath,
        ]);

        if ($affected === 0) {
            // Запись изменилась во время переноса: не перезаписываем её и
            // удаляем только что созданный нами объект.
            $this->deleteOwnTarget($targetDisk, $targetPath);
            $this->conflicts++;
            $this->line("  <error>!</error> {$this->label($model)} avatar changed concurrently; kept the newer value and removed the copied object");

            return;
        }

        $this->migrated++;
        $this->line("  <info>✓</info> {$this->label($model)} [{$sourceDisk}:{$sourcePath}] -> [{$targetDisk}:{$targetPath}] ({$sourceHash})");
    }

    /**
     * Условное обновление: только если исходный аватар не изменился с момента чтения.
     *
     * @param  array<string, string|null>  $values
     */
    private function conditionalUpdate(Model $model, string $originalUrl, mixed $observedDisk, mixed $observedPath, array $values): int
    {
        try {
            $query = $model->newQuery()
                ->whereKey($model->getKey())
                ->where('avatar_url', $originalUrl);

            if ($observedDisk === null) {
                $query->whereNull('avatar_disk');
            } else {
                $query->where('avatar_disk', $observedDisk);
            }

            if ($observedPath === null) {
                $query->whereNull('avatar_path');
            } else {
                $query->where('avatar_path', $observedPath);
            }

            return $query->update($values);
        } catch (Throwable $e) {
            $this->failed++;
            $this->line("  <error>!</error> {$this->label($model)} DB update failed: {$e->getMessage()}");

            return 0;
        }
    }

    private function deleteOwnTarget(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Наш объект мог и не создаться; ошибка удаления не критична.
        }
    }

    private function targetDirectory(string $sourcePath): string
    {
        $pos = strrpos($sourcePath, '/');

        return $pos === false ? '' : substr($sourcePath, 0, $pos + 1);
    }

    private function extension(string $sourcePath): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'jpg';
    }

    private function label(Model $model): string
    {
        $table = $model->getTable();

        return "{$table}[{$model->getKey()}]";
    }

    private function summary(bool $dryRun): int
    {
        $this->newLine();
        $this->info('Summary:');

        if ($dryRun) {
            $this->line("  would migrate: {$this->planned}");
        } else {
            $this->line("  migrated: {$this->migrated}");
        }

        $this->line("  already migrated: {$this->alreadyMigrated}");
        $this->line("  skipped (foreign/external): {$this->foreign}");

        if (! $dryRun) {
            $this->line("  conflicts: {$this->conflicts}");
            $this->line("  missing local files: {$this->missing}");
            $this->line("  verification mismatches: {$this->mismatches}");
            $this->line("  S3/DB errors: {$this->failed}");
        }

        $errors = $this->conflicts + $this->missing + $this->mismatches + $this->failed;

        if (! $dryRun && $errors > 0) {
            $this->error("Completed with {$errors} error(s). Local originals were not deleted.");

            return self::FAILURE;
        }

        $this->info('Local originals were not deleted.');

        return self::SUCCESS;
    }
}
