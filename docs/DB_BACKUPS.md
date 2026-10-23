# Резервные копии PostgreSQL (managed backups)

Управляемые резервные копии БД приложения: `pg_dump` в custom format,
загрузка в отдельный приватный S3-бакет (Timeweb), верификация размера и
SHA-256, ротация. Доступ к разделу — только у ROOT-пользователя
(`is_super_admin`) в суперадминке (`/admin-root/backups`).

> **Важно:** это не замена серверного бэкапа Timeweb. Текущий серверный
> бэкап отключать не нужно и не следует — managed-копии дополняют его.

## Что копируется и что нет

`pg_dump` одной базы данных сохраняет схемы и данные этой БД. Он **не**
сохраняет:

- роли/пользователей PostgreSQL (`pg_dumpall --globals-only` — отдельно);
- `.env` приложения (включая `APP_KEY` — сохраните его отдельно!);
- серверные настройки PostgreSQL (`postgresql.conf`, `pg_hba.conf`);
- S3-аватары и прочие объекты бакетов (MEDIA_* — отдельная тема).

## Архитектура

- **Сервис** `App\Services\Backups\DatabaseBackupService` — единая точка
  для ручного запуска, расписания и Artisan-команды.
- **Очередь**: connection `backups` (database driver, таблица `jobs`),
  очередь `backups`, отдельный Supervisor-процесс. Общая очередь
  `default` не меняется.
- **Job** `RunDatabaseBackupJob` — `tries=1`, `timeout=900` c (конфиг
  `backups.job_timeout`). Сериализуется только UUID копии.
- **Исполнение**: `pg_dump --format=custom` (без остановки приложения),
  аргументы — массивом, без shell. Пароль передаётся только через
  `PGPASSFILE` (файл 0600 во временной директории копии).
- **Верификация**: exit code `pg_dump`, ненулевой размер, читаемость
  архива через `pg_restore --list` (это **не** проверенное
  восстановление), потоковая загрузка в S3, затем повторное чтение
  объекта и сверка размера и SHA-256. Только после этого копия получает
  статус `success`.
- **Manifest**: рядом с дампом лежит `<object>.manifest.json` — id, время,
  размер, SHA-256, версия PostgreSQL, версия приложения. Проверить копию
  можно без работающей Laravel-БД.
- **Блокировки**: partial unique index — не более одной активной копии
  (queued/running) одновременно; для scheduled — дедупликация за
  календарный день (Europe/Moscow), failed-день позволяет повтор.
- **Ротация**: последние 7 успешных копий scheduled и последние 7
  успешных manual хранятся отдельно (`BACKUP_KEEP_SCHEDULED`,
  `BACKUP_KEEP_MANUAL`). Старые удаляются только после полной проверки
  новой копии; сбой очистки не делает новую копию невалидной
  (`prune_error_message`).

## Настройка (server side)

1. `.env` (ключи — только на сервере, в репозиторий/`env.example` не
   вписывать):

```dotenv
BACKUP_S3_KEY=<ключ irsi-app-backups>
BACKUP_S3_SECRET=<секрет irsi-app-backups>
BACKUP_S3_REGION=ru-1
BACKUP_S3_BUCKET=irsi-db-backups
BACKUP_S3_ENDPOINT=https://s3.twcstorage.ru
BACKUP_S3_PATH_STYLE=true
BACKUP_S3_PREFIX=irsi-app

# Ежедневный автозапуск в 03:30 Europe/Moscow (по умолчанию выключен)
BACKUP_SCHEDULED_ENABLED=true

BACKUP_KEEP_SCHEDULED=7
BACKUP_KEEP_MANUAL=7
```

2. Бинарники `pg_dump`/`pg_restore` 16.x должны быть доступны пользователю
   воркера (путь задаётся `BACKUP_PG_DUMP_PATH`/`BACKUP_PG_RESTORE_PATH`,
   по умолчанию из PATH). Пользователь приложения **не** должен иметь
   sudo или прав `postgres` — подключение идёт по обычным
   server-параметрам БД из `DB_*`.

3. Отдельный Supervisor-процесс (пример — см. файл ниже; на сервер ничего
   не устанавливается этим репозиторием):

```ini
; deploy/supervisor/queue-backups.conf.example

[program:queue-backups]
process_name=%(program_name)s
command=php /path/to/app/artisan queue:work backups --sleep=3 --tries=1 --max-time=3300
directory=/path/to/app
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/queue-backups.log
stopwaitsecs=950
```

4. `php artisan migrate` — создаёт таблицу `database_backups`.

5. Scheduler уже запускается root-cron; в `routes/console.php`
   зарегистрированы `backups:schedule-daily` (03:30 Europe/Moscow,
   срабатывает только при `BACKUP_SCHEDULED_ENABLED=true`) и почасовая
   уборка временных файлов `backups:cleanup-tmp`.

### Согласование таймаутов

| Параметр | Значение | Где |
|---|---|---|
| subprocess pg_dump/pg_restore | 600 с | `backups.process_timeout` |
| job timeout | 900 с | `backups.job_timeout` |
| queue retry_after (connection `backups`) | 1200 с | `BACKUP_QUEUE_RETRY_AFTER` |
| Supervisor stopwaitsecs | 950 с | см. пример |
| worker max-time | 3300 с | см. пример |
| stale running | 1800 с | `backups.stale_after` |

Инвариант: `process_timeout < job_timeout < retry_after`; `stopwaitsecs >
job_timeout`, чтобы graceful-stop не убивал копию посреди загрузки.

### Права на файлы

Временные дампы и pgpass создаются в
`storage/framework/backups-tmp/<uuid>/` (0700 директория, 0600 файлы).
`umask` SSH-сессии не меняется. После успеха и обрабатываемых ошибок
временные файлы убираются; после аварийного завершения — командой
`backups:cleanup-tmp` (часовой cron scheduler).

## Ручной запуск

- Кнопка «Создать копию» в суперадминке (`/admin-root/backups`) — только
  для ROOT; ставит job в очередь, не запускает `pg_dump` в HTTP-запросе.
- `php artisan backups:run` — то же из CLI.
- Ручной запуск и его результат пишутся в аудит
  (`backup.requested` / `backup.succeeded` / `backup.failed`).

## Проверка копии без Laravel

1. Скачать объект и manifest из бакета (s3cmd / AWS CLI / веб-консоль
   Timeweb — бакет приватный):

```bash
s3cmd get s3://irsi-db-backups/production/scheduled/2026/10/26/<uuid>.dump .
s3cmd get s3://irsi-db-backups/production/scheduled/2026/10/26/<uuid>.dump.manifest.json .
```

2. Сверить SHA-256:

```bash
sha256sum <uuid>.dump
# сравнить с полем sha256 в <uuid>.dump.manifest.json
```

3. Проверить читаемость архива:

```bash
pg_restore --list <uuid>.dump | head
```

## Восстановление (только в НОВУЮ отдельную БД!)

Восстановление в production из этих инструкций по умолчанию не
выполняется. Категорически не выполнять `dropdb`/`createdb` для
production-БД и не использовать `--clean --if-exists` на живой базе.

```bash
# 1. Создать НОВУЮ пустую базу (не production!)
createuser -s irsi_restore   # от пользователя postgres, при необходимости
createdb -O irsi_app irsi_app_restore

# 2. Восстановить дамп в новую базу
PGPASSWORD=... pg_restore -h 127.0.0.1 -p 5432 -U irsi_app \
  -d irsi_app_restore --no-owner --no-privileges <uuid>.dump

# 3. Сверить контрольную сумму ещё раз после переноса
sha256sum <uuid>.dump
```

После восстановления в новую БД при необходимости переключите тестовый
стенд на неё (`DB_DATABASE=irsi_app_restore`) и отдельно перенесите
`.env` (в т.ч. `APP_KEY`), роли PostgreSQL и объекты аватаров.

## Поведение при сбоях

- **Сбой между загрузкой дампа и manifest / записью успеха в БД**: статус
  остаётся `running` и через `stale_after` (30 мин) помечается прерванной;
  job при этом уже упал по `job_timeout`/`tries=1` и его `failed()`-хук
  фиксирует прерывание. Повторная доставка job идемпотентна: success/failed
  — no-op, свежий running не трогается. Дозагруженный в S3 объект не
  помечается успешным (success выставляется только после полной сверки);
  следующий запуск создаст новый уникальный объект, старый будет удалён
  ротацией, если останется лишним.
- **Сбой БД во время записи ошибки**: сообщение уходит как минимум в
  системный лог (`storage/logs`) и в stderr воркера (Supervisor-лог);
  database-уведомление ROOT при недоступной БД не обещается.
- **Уведомления**: при сбое копии всем ROOT-пользователям создаётся
  database-уведомление (второй канал — `App\Notifications\SystemNotification`).
  Email/MAX/VK не используются; в текстах нет секретов.

## Ограничения v1

- Восстановление из суперадмина не запускается (только вручную по
  инструкции выше, в новую БД).
- Публичных ссылок и скачивания дампа из браузера нет.
- Реальный `pg_dump`/`pg_restore` в тестах не выполняется (fake runner);
  приёмка на живом PostgreSQL 16 — отдельная ручная проверка.
