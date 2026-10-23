<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Media Disks (avatars)
        |--------------------------------------------------------------------------
        |
        | Два конкретных диска для аватаров: media_local (локальный) и
        | media_s3 (S3). MEDIA_DRIVER выбирает только диск, на который пишутся
        | НОВЫЕ аватары (см. config/avatars.php); конфигурация каждого диска
        | от режима не зависит, поэтому сохранённые в БД идентификаторы не
        | меняют смысл. Дефолтный FILESYSTEM_DISK и существующие диски
        | local/public/s3 не переключаются.
        |
        */

        'media_local' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        'media_s3' => [
            'driver' => 's3',
            'key' => env('MEDIA_S3_KEY'),
            'secret' => env('MEDIA_S3_SECRET'),
            'region' => env('MEDIA_S3_REGION', 'ru-1'),
            'bucket' => env('MEDIA_S3_BUCKET'),
            'endpoint' => env('MEDIA_S3_ENDPOINT'),
            'url' => env('MEDIA_PUBLIC_URL'),
            'use_path_style_endpoint' => env('MEDIA_S3_PATH_STYLE', true),
            'throw' => true,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Backup Disk (дампы PostgreSQL)
        |--------------------------------------------------------------------------
        |
        | Отдельный приватный холодный бакет для резервных копий БД.
        | Собственные BACKUP_S3_* переменные: MEDIA_* и AWS_* не читаются,
        | публичный URL не формируется.
        |
        */

        'backups_s3' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'ru-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_S3_PATH_STYLE', true),
            // Явные HTTP-таймауты клиента AWS SDK (не путать с job/subprocess).
            'http' => [
                'connect_timeout' => (float) env('BACKUP_S3_CONNECT_TIMEOUT', 10),
                'timeout' => (float) env('BACKUP_S3_REQUEST_TIMEOUT', 60),
            ],
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
