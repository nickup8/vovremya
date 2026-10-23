<?php

namespace App\Services\Avatars;

use App\Exceptions\AvatarStorageException;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Единственная точка записи/замены/удаления аватаров для всех писателей
 * (SettingsController, TelegramWebhookHandler, SyncClientAvatarsCommand).
 *
 * Порядок замены: сначала успешная запись нового файла, затем условное
 * (CAS) обновление строки по наблюдавшемуся avatar_url, затем удаление
 * старого принадлежащего приложению файла. DB-транзакций во время
 * S3 HTTP-запросов не открывается.
 */
class AvatarStorageService
{
    /**
     * Конкретный диск, на который пишутся новые аватары (media_local или
     * media_s3). Существующий файл разрешается по сохранённому avatar_disk,
     * а не по этому режиму.
     */
    public function disk(): string
    {
        $disk = config('avatars.disk');

        return is_string($disk) && $disk !== '' ? $disk : 'media_local';
    }

    /**
     * Публичный URL для пути на диске. Для локальных дисков — относительный
     * /storage/... (старый контракт фронтенда), для S3 — MEDIA_PUBLIC_URL.
     *
     * @throws AvatarStorageException если публичный URL для S3 не настроен
     */
    public function url(string $disk, string $path): string
    {
        $driver = config("filesystems.disks.{$disk}.driver");
        $path = ltrim($path, '/');

        if ($driver !== 's3') {
            $prefix = (string) config('avatars.legacy_url_prefix', '/storage/');

            return rtrim($prefix, '/').'/'.$path;
        }

        $base = config("filesystems.disks.{$disk}.url");

        if (! is_string($base) || trim($base) === '') {
            throw new AvatarStorageException(
                "Public URL is not configured for disk [{$disk}]: set MEDIA_PUBLIC_URL."
            );
        }

        return rtrim(trim($base), '/').'/'.$path;
    }

    /**
     * Записывает новый аватар и заменяет старый.
     *
     * При любой ошибке записи/URL/БД старый аватар остаётся нетронутым,
     * а уже записанный новый файл удаляется.
     *
     * @return string публичный URL нового аватара
     *
     * @throws AvatarStorageException
     */
    public function replace(User|Client $model, string $contents, ?string $extension = null): string
    {
        if ($contents === '') {
            throw new AvatarStorageException('Refusing to store an empty avatar payload.');
        }

        $disk = $this->disk();
        $oldUrl = $model->avatar_url;
        $oldFile = $model->avatarReference();
        $path = $model->avatarDirectory().Str::random(40).'.'.$this->normalizeExtension($extension);

        try {
            $written = Storage::disk($disk)->put($path, $contents);
        } catch (Throwable $e) {
            $this->bestEffortDelete($disk, $path);

            throw new AvatarStorageException(
                "Failed to write avatar to [{$disk}:{$path}]: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($written !== true) {
            throw new AvatarStorageException("Failed to write avatar to [{$disk}:{$path}].");
        }

        try {
            $newUrl = $this->url($disk, $path);
        } catch (Throwable $e) {
            $this->bestEffortDelete($disk, $path);

            throw $e;
        }

        try {
            $affected = $this->casUpdate($model, $oldUrl, [
                'avatar_url' => $newUrl,
                'avatar_disk' => $disk,
                'avatar_path' => $path,
            ]);
        } catch (Throwable $e) {
            // БД не приняла новый URL: удаляем новый файл, старый аватар
            // и его URL остаются нетронутыми.
            $this->bestEffortDelete($disk, $path);

            throw new AvatarStorageException(
                "Avatar was written but the DB update failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($affected === 0) {
            // Чей-то более новый аватар уже записан: не перезаписываем его
            // и удаляем только что созданный нами файл.
            $this->bestEffortDelete($disk, $path);

            throw new AvatarStorageException(
                'Avatar was replaced concurrently; keeping the newer avatar.'
            );
        }

        $this->syncAvatarAttributes($model, [
            'avatar_url' => $newUrl,
            'avatar_disk' => $disk,
            'avatar_path' => $path,
        ]);

        if ($oldFile['owned'] && $oldFile['disk'] !== null && $oldFile['path'] !== null) {
            $this->deleteOwnedFile($oldFile['disk'], $oldFile['path']);
        }

        return $newUrl;
    }

    /**
     * Удаляет аватар: сначала условно очищает строку, затем — только
     * принадлежащий приложению файл. Если запись изменилась конкурентно,
     * новый файл не удаляется.
     */
    public function remove(User|Client $model): bool
    {
        $oldUrl = $model->avatar_url;

        if ($oldUrl === null) {
            return true;
        }

        $oldFile = $model->avatarReference();

        $affected = $this->casUpdate($model, $oldUrl, [
            'avatar_url' => null,
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        if ($affected === 0) {
            return false;
        }

        $this->syncAvatarAttributes($model, [
            'avatar_url' => null,
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        if ($oldFile['owned'] && $oldFile['disk'] !== null && $oldFile['path'] !== null) {
            $this->deleteOwnedFile($oldFile['disk'], $oldFile['path']);
        }

        return true;
    }

    /**
     * Удаляет файл, только если он принадлежит приложению: диск из
     * owned-списка и путь внутри разрешённого avatar-префикса.
     */
    public function deleteOwnedFile(string $disk, string $path): bool
    {
        if (! in_array($disk, AvatarPath::ownedDisks(), true)) {
            Log::warning('avatars: refused to delete file on unowned disk', [
                'disk' => $disk,
                'path' => $path,
            ]);

            return false;
        }

        if (! AvatarPath::isAllowed($path)) {
            Log::warning('avatars: refused to delete file outside allowed prefixes', [
                'disk' => $disk,
                'path' => $path,
            ]);

            return false;
        }

        try {
            return Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('avatars: failed to delete old avatar file', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Сравнение по наблюдавшемуся avatar_url: конкурентная операция
     * не сможет ни перезаписать, ни удалить более новый аватар.
     *
     * @param  array<string, string|null>  $values
     */
    private function casUpdate(User|Client $model, ?string $observedUrl, array $values): int
    {
        $query = $model->newQuery()->whereKey($model->getKey());

        if ($observedUrl === null) {
            $query->whereNull('avatar_url');
        } else {
            $query->where('avatar_url', $observedUrl);
        }

        return $query->update($values);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function syncAvatarAttributes(User|Client $model, array $values): void
    {
        $model->forceFill($values);
        $model->syncOriginalAttributes(array_keys($values));
    }

    private function bestEffortDelete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Файл мог и не создаться — ошибка удаления здесь не критична.
        }
    }

    private function normalizeExtension(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension));

        if ($extension === '' || ! preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            return 'jpg';
        }

        return $extension;
    }
}
