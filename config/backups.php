<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk
    |--------------------------------------------------------------------------
    |
    | Отдельный приватный S3-диск для дампов (см. backups_s3 в
    | config/filesystem.php). MEDIA_* и публичные URL не используются.
    |
    */

    'disk' => env('BACKUP_DISK', 'backups_s3'),

    /*
    |--------------------------------------------------------------------------
    | Object Prefix
    |--------------------------------------------------------------------------
    |
    | Корневой префикс объектов приложения в бакете. Внутри него окружение
    | (APP_ENV) и тип запуска (manual/scheduled) разделяются дальше. Удаление
    | затрагивает только объекты, начинающиеся с этого префикса.
    |
    */

    'prefix' => rtrim((string) env('BACKUP_S3_PREFIX', 'irsi-app'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Сколько последних УСПЕШНЫХ копий хранить отдельно для расписания и
    | ручных запусков. Старые удаляются только после появления новой
    | полностью проверенной копии того же типа.
    |
    */

    'keep' => [
        'scheduled' => max(1, (int) env('BACKUP_KEEP_SCHEDULED', 7)),
        'manual' => max(1, (int) env('BACKUP_KEEP_MANUAL', 7)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Ежедневный автоматический запуск. По умолчанию выключен — включается
    | BACKUP_SCHEDULED_ENABLED=true на сервере.
    |
    */

    'scheduled' => [
        'enabled' => (bool) env('BACKUP_SCHEDULED_ENABLED', false),
        'time' => env('BACKUP_SCHEDULED_TIME', '03:30'),
        'timezone' => env('BACKUP_SCHEDULED_TIMEZONE', 'Europe/Moscow'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    */

    'pg_dump' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
    'pg_restore' => env('BACKUP_PG_RESTORE_PATH', 'pg_restore'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (секунды)
    |--------------------------------------------------------------------------
    |
    | Согласованная цепочка: S3 connect/request, process_timeout,
    | job_timeout < retry_after < stopwaitsecs в Supervisor-процессе
    | бэкапов. Подробнее — docs/DB_BACKUPS.md.
    |
    */

    // HTTP connect-timeout клиента S3 (отдельный от Laravel Http-фасада).
    's3_connect_timeout' => max(1, (int) env('BACKUP_S3_CONNECT_TIMEOUT', 10)),

    // HTTP request timeout клиента S3 (один HTTP-запрос к бакету).
    's3_request_timeout' => max(5, (int) env('BACKUP_S3_REQUEST_TIMEOUT', 60)),

    // Жёсткий таймаут pg_dump/pg_restore subprocess.
    'process_timeout' => max(60, (int) env('BACKUP_PROCESS_TIMEOUT', 600)),

    // Таймаут Job (worker убивает процесс и помечает job failed).
    'job_timeout' => max(120, (int) env('BACKUP_JOB_TIMEOUT', 900)),

    // retry_after соединения очереди 'backups' (config/queue.php) должен
    // быть больше job_timeout — см. BACKUP_QUEUE_RETRY_AFTER.
    'retry_after' => max(240, (int) env('BACKUP_QUEUE_RETRY_AFTER', 1200)),

    // running старше этого срока считается зависшим и помечается прерванной.
    'stale_after' => max(600, (int) env('BACKUP_STALE_AFTER', 1800)),

    /*
    |--------------------------------------------------------------------------
    | Freshness Warning
    |--------------------------------------------------------------------------
    |
    | Если успешной копии нет дольше этого числа часов — суперадминка
    | показывает предупреждение.
    |
    */

    'warning_after_hours' => max(1, (int) env('BACKUP_WARNING_AFTER_HOURS', 48)),

    /*
    |--------------------------------------------------------------------------
    | Temp Directory
    |--------------------------------------------------------------------------
    |
    | Временные дампы и pgpass-файл лежат вне public, в уникальной
    | поддиректории с правами 0700/0600. umask SSH-сессии не меняется.
    |
    */

    'tmp_dir' => storage_path('framework/backups-tmp'),

    // Уборка временных файлов старше N часов после аварийных завершений.
    'tmp_max_age_hours' => max(1, (int) env('BACKUP_TMP_MAX_AGE_HOURS', 24)),

];
