<?php

namespace App\Console\Commands;

use App\Exceptions\Backups\BackupException;
use App\Services\Backups\BackupError;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ежедневная резервная копия объектов avatars/ из рабочего диска media_s3
 * в приватный резервный диск (config('backups.disk'), на production — бакет
 * irsi-db-backups) под префиксом irsi-media-backup/<APP_ENV>/ с сохранением
 * исходных ключей.
 *
 * Рабочий бакет только читается; в резерве ничего не удаляется — удаление
 * исходника не трогает копию, а повторный запуск дозаполняет недостающее
 * и переписывает расхождения. Включение — AVATAR_BACKUP_ENABLED (по
 * умолчанию выключен). Итог запуска пишется в журнал приложения (Log)
 * со статусом и счётчиками — без ключей объектов, URL и секретов.
 */
class BackupAvatarsDaily extends Command
{
    /** Копируется только этот префикс источника; ключи сохраняются как есть. */
    private const SOURCE_PREFIX = 'avatars/';

    /** Максимум ошибок в итоговом списке (остальные — счётчиком). */
    private const MAX_REPORTED_ERRORS = 20;

    /** Сообщение итога в журнале приложения (у дампов префикс «Backups:»). */
    private const JOURNAL_MESSAGE = 'Backups: итог копирования аватаров';

    protected $signature = 'backups:avatars-daily';

    protected $description = 'Копировать avatars/ из media_s3 в приватный резервный диск (irsi-media-backup/<env>/)';

    private int $copied = 0;

    private int $present = 0;

    private int $failed = 0;

    /** @var list<array{key: string, reason: string}> */
    private array $errors = [];

    public function handle(): int
    {
        // Команда может вызываться повторно в одном процессе (Artisan::call):
        // счётчики прошлого запуска не должны попадать в текущий итог.
        $this->copied = 0;
        $this->present = 0;
        $this->failed = 0;
        $this->errors = [];

        if (! config('backups.avatars.enabled')) {
            $this->info('Резервное копирование аватаров выключено (AVATAR_BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        $sourceDisk = (string) config('backups.avatars.source_disk', 'media_s3');
        $targetDisk = (string) config('backups.disk');
        $refusal = $this->refuseUnsafeTarget($sourceDisk, $targetDisk);

        if ($refusal !== null) {
            $this->error($refusal);
            $this->journal('config_error', self::zeroCounters(), [$this->sanitize($refusal)]);

            return self::FAILURE;
        }

        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);

        try {
            $keys = $source->allFiles(self::SOURCE_PREFIX);
        } catch (Throwable $e) {
            $reason = $this->sanitize($e->getMessage());
            $this->error('Не удалось получить список объектов источника: '.$reason);
            $this->journal('list_error', self::zeroCounters(), [$reason]);

            return self::FAILURE;
        }

        $keys = array_values(array_filter(
            $keys,
            fn (string $key): bool => str_starts_with($key, self::SOURCE_PREFIX),
        ));
        sort($keys);

        $namespace = $this->targetNamespace();

        foreach ($keys as $key) {
            $this->copyOne($source, $target, $key, $namespace);
        }

        return $this->summary($sourceDisk, $targetDisk, count($keys));
    }

    /**
     * Отказ до любых обращений к S3: источник и назначение обязаны быть
     * разными (бакет/каталог/диск), а префикс резервной копии — не
     * пересекаться с префиксом дампов, иначе ротация дампов могла бы
     * затронуть объекты аватаров и наоборот.
     */
    private function refuseUnsafeTarget(string $sourceDisk, string $targetDisk): ?string
    {
        if ($sourceDisk === '') {
            return 'Диск-источник не задан (backups.avatars.source_disk).';
        }

        if ($targetDisk === '') {
            return 'Резервный диск не задан (backups.disk).';
        }

        if ($sourceDisk === $targetDisk) {
            return "Источник и назначение совпадают (диск [{$sourceDisk}]) — копирование отменено.";
        }

        $source = config("filesystems.disks.{$sourceDisk}");
        $target = config("filesystems.disks.{$targetDisk}");

        if (! is_array($source)) {
            return "Диск-источник [{$sourceDisk}] не настроен — копирование отменено.";
        }

        if (! is_array($target)) {
            return "Резервный диск [{$targetDisk}] не настроен — копирование отменено.";
        }

        $sourceBucket = trim((string) ($source['bucket'] ?? ''));
        $targetBucket = trim((string) ($target['bucket'] ?? ''));

        if ($sourceBucket !== '' && $sourceBucket === $targetBucket) {
            return 'Рабочий и резервный бакеты совпадают — копирование отменено.';
        }

        $sourceRoot = rtrim((string) ($source['root'] ?? ''), '/\\');
        $targetRoot = rtrim((string) ($target['root'] ?? ''), '/\\');

        if (($source['driver'] ?? null) === 'local'
            && ($target['driver'] ?? null) === 'local'
            && $sourceRoot !== ''
            && $sourceRoot === $targetRoot) {
            return 'Источник и назначение указывают на один каталог — копирование отменено.';
        }

        $avatarPrefix = trim((string) config('backups.avatars.prefix'), '/');
        $dumpPrefix = trim((string) config('backups.prefix'), '/');

        if ($avatarPrefix === '') {
            return 'Префикс резервной копии аватаров пуст — копирование отменено.';
        }

        if ($dumpPrefix === '') {
            return 'Префикс дампов (backups.prefix) пуст — копирование отменено.';
        }

        if ($avatarPrefix === $dumpPrefix
            || str_starts_with($avatarPrefix, $dumpPrefix.'/')
            || str_starts_with($dumpPrefix, $avatarPrefix.'/')) {
            return 'Префикс резервной копии аватаров пересекается с префиксом дампов — копирование отменено.';
        }

        return null;
    }

    /**
     * Один объект: чтение из источника → дозаполнение/сверка в резерве →
     * контрольное чтение с проверкой размера и SHA-256. Ошибка объекта не
     * прерывает остальные: следующий запуск дозаполнит пропущенное.
     */
    private function copyOne(Filesystem $source, Filesystem $target, string $key, string $namespace): void
    {
        $targetKey = $namespace.$key;

        try {
            $contents = $source->get($key);

            if (! is_string($contents)) {
                throw new BackupException('Источник не вернул содержимое объекта.');
            }

            $size = strlen($contents);
            $hash = hash('sha256', $contents);

            // Уже лежащая идентичная копия не переписывается — повторный
            // запуск после сбоя дозаполняет только недостающее/расходящееся.
            if ($target->exists($targetKey)) {
                $existing = $target->get($targetKey);

                if (! is_string($existing)) {
                    // Существующий объект не читается (null): ошибка объекта,
                    // put для этого ключа не выполняется — вслепую не
                    // перезаписываем и не затираем то, что не смогли проверить.
                    // Исключение при чтении уходит в общий catch ниже.
                    throw new BackupException('Не удалось прочитать существующий объект резерва.');
                }

                if (strlen($existing) === $size && hash('sha256', $existing) === $hash) {
                    $this->present++;

                    return;
                }
            }

            if ($target->put($targetKey, $contents) !== true) {
                throw new BackupException('Запись в резервный диск не подтверждена.');
            }

            $stored = $target->get($targetKey);

            if (! is_string($stored) || strlen($stored) !== $size || hash('sha256', $stored) !== $hash) {
                throw new BackupException('Контрольное чтение не совпало с источником (размер/SHA-256).');
            }

            $this->copied++;
        } catch (Throwable $e) {
            $this->failed++;
            $this->errors[] = [
                'key' => $key,
                'reason' => $this->sanitize($e->getMessage()),
            ];
        }
    }

    private function targetNamespace(): string
    {
        return sprintf(
            '%s/%s/',
            trim((string) config('backups.avatars.prefix'), '/'),
            (string) config('app.env'),
        );
    }

    private function summary(string $sourceDisk, string $targetDisk, int $total): int
    {
        $this->newLine();
        $this->line('Источник: ['.self::SOURCE_PREFIX.'] диск ['.$sourceDisk.']');
        $this->line('Назначение: ['.$this->targetNamespace().'] диск ['.$targetDisk.']');
        $this->line('Объектов в источнике: '.$total);
        $this->line('Скопировано: '.$this->copied);
        $this->line('Уже в резерве: '.$this->present);

        $counters = [
            'total' => $total,
            'copied' => $this->copied,
            'present' => $this->present,
            'failed' => $this->failed,
        ];

        if ($this->failed === 0) {
            $this->info('Ошибок нет; файлы источника не изменялись, из резерва ничего не удалялось.');
            $this->journal('success', $counters);

            return self::SUCCESS;
        }

        $this->error('Ошибок копирования: '.$this->failed);

        foreach (array_slice($this->errors, 0, self::MAX_REPORTED_ERRORS) as $error) {
            $this->line('  - '.$error['key'].': '.$error['reason']);
        }

        $rest = count($this->errors) - self::MAX_REPORTED_ERRORS;

        if ($rest > 0) {
            $this->line('  … и ещё '.$rest);
        }

        $this->journal(
            'copy_error',
            $counters,
            array_slice(
                array_map(
                    static fn (array $error): string => $error['reason'],
                    $this->errors,
                ),
                0,
                self::MAX_REPORTED_ERRORS,
            ),
        );

        return self::FAILURE;
    }

    /**
     * Итог запуска в журнал приложения (Log — тот же журнал, куда пишутся
     * итоги дампов БД). Статус и счётчики; ошибки — только причины, без
     * ключей объектов, URL и секретов.
     *
     * @param  array<string, int>  $counters
     * @param  list<string>  $errors
     */
    private function journal(string $status, array $counters, array $errors = []): void
    {
        $context = array_merge(['status' => $status], $counters);

        if ($errors !== []) {
            $context['errors'] = array_values(array_unique($errors));
        }

        if ($status === 'success') {
            Log::info(self::JOURNAL_MESSAGE, $context);
        } else {
            Log::error(self::JOURNAL_MESSAGE, $context);
        }
    }

    /** @return array<string, int> */
    private static function zeroCounters(): array
    {
        return ['total' => 0, 'copied' => 0, 'present' => 0, 'failed' => 0];
    }

    /**
     * Без секретов и без URL (endpoint S3, публичные URL аватаров) —
     * текст пригоден для вывода в cron-лог.
     */
    private function sanitize(string $message): string
    {
        $message = BackupError::sanitize($message);

        foreach ($this->diskSecrets() as $secret) {
            $message = str_replace($secret, '***', $message);
        }

        $message = (string) preg_replace('#[a-z][a-z0-9+.-]*://\S+#iu', '<URL>', $message);

        return Str::limit(trim($message), 300);
    }

    /** @return list<string> */
    private function diskSecrets(): array
    {
        $disks = array_unique([
            (string) config('backups.avatars.source_disk', 'media_s3'),
            (string) config('backups.disk'),
        ]);

        $secrets = [];

        foreach ($disks as $disk) {
            foreach (['key', 'secret'] as $field) {
                $value = (string) config("filesystems.disks.{$disk}.{$field}");

                if (strlen($value) >= 6) {
                    $secrets[$value] = $value;
                }
            }
        }

        return array_values($secrets);
    }
}
