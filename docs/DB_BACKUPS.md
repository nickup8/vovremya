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
- **Job** `RunDatabaseBackupJob` — `tries=1`, `timeout=900` с (конфиг
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
  размер, SHA-256, версия PostgreSQL, версия приложения (`APP_VERSION` —
  **фактический git SHA деплоя**, см. deploy.sh; при локальной разработке
  может быть подписанный тег или `dev`). Проверить копию можно без
  работающей Laravel-БД.
- **Блокировки**: partial unique index — не более одной активной копии
  (queued/running) одновременно; для scheduled — дедупликация за
  календарный день (Europe/Moscow), failed-день позволяет повтор.
- **Атомарность постановки**: при database-очереди на **том же**
  DB-соединении (см. `BACKUP_DB_QUEUE_CONNECTION` ниже) строка
  `database_backups` и job в `jobs` коммитятся одной транзакцией —
  сбой dispatch не оставляет вечный `queued`.
- **Восстановление**: команда `backups:recover` (каждые 15 минут,
  **не** зависит от `BACKUP_SCHEDULED_ENABLED`) отличает:
  - `queued` с job в `jobs` — ждёт живого воркера, не трогается;
  - `queued` **без** job — потерянное задание, повторная постановка;
  - `running` старше `stale_after` — прерванное выполнение → `failed`,
    уведомление ROOT и очистка незавершённых S3-объектов.
  Вторая копия поверх живого процесса не запускается: `begin()` —
  атомарный CAS по `status` + `execution_token`; запоздавший исполнитель
  не может записать success/error чужого запуска и не запускает ротацию.
  Дубликат доставки job, проигравший гонку захвата, — no-op
  (`BackupCaptureLost`): failed()-хук не помечает прерванной чужую
  живую попытку.
- **Учёт незавершённых объектов**: ключи dump/manifest записываются в
  БД **до** HTTP-загрузки. При сбое/прерывании удаляются только объекты
  этого запуска в namespace `{prefix}/{APP_ENV}/{trigger}/…` с полным
  id копии в имени; `delete===false` считается ошибкой. Успешные копии
  и чужие ключи не затрагиваются.
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

# HTTP-таймауты клиента S3 (один запрос)
BACKUP_S3_CONNECT_TIMEOUT=10
BACKUP_S3_REQUEST_TIMEOUT=60

# Ежедневный автозапуск в 03:30 Europe/Moscow (по умолчанию выключен)
BACKUP_SCHEDULED_ENABLED=true

BACKUP_KEEP_SCHEDULED=7
BACKUP_KEEP_MANUAL=7
```

2. **`BACKUP_DB_QUEUE_CONNECTION`** (опционально, connection из
   `config/queue.php` для очереди бэкапов):

   - **не задан / пусто** (рекомендуется): job пишется в таблицу `jobs`
     на **том же** DB-соединении, что и приложение. Постановка
     атомарна: rollback транзакции убирает и копию, и job.
   - **задан и указывает на другое соединение/СУБД**: атомарность
     невозможна. Между коммитом `database_backups` и INSERT job есть
     окно; сбой dispatch помечает копию `failed` (не вечный `queued`).
     Потерянные `queued` без job восстанавливает `backups:recover`
     (после `retry_after`). `after_commit=true` для connection `backups`
     также отключает атомарный путь.

3. Бинарники `pg_dump`/`pg_restore` 16.x должны быть доступны пользователю
   воркера (путь задаётся `BACKUP_PG_DUMP_PATH`/`BACKUP_PG_RESTORE_PATH`,
   по умолчанию из PATH). Пользователь приложения **не** должен иметь
   sudo или прав `postgres` — подключение идёт по обычным
   server-параметрам БД из `DB_*`.

4. Отдельный Supervisor-процесс (пример — см. файл ниже; на сервер ничего
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

5. `php artisan migrate` — создаёт таблицу `database_backups` и
   `execution_token`.

6. Scheduler уже запускается root-cron; в `routes/console.php`
   зарегистрированы `backups:schedule-daily` (03:30 Europe/Moscow,
   срабатывает только при `BACKUP_SCHEDULED_ENABLED=true`),
   `backups:recover` (каждые 15 минут, всегда) и почасовая уборка
   временных файлов `backups:cleanup-tmp`.

7. **`APP_VERSION`** — deploy.sh после `git pull` пишет в `.env`
   `APP_VERSION=$(git rev-parse HEAD)`. Manifest и поле `app_version`
   содержат этот git SHA. Не оставляйте в production шаблонное
   `APP_VERSION=1.0.0`.

### Согласование таймаутов

| Параметр | Значение | Где |
|---|---|---|
| S3 HTTP connect | 10 с | `backups.s3_connect_timeout` |
| S3 HTTP request | 60 с | `backups.s3_request_timeout` |
| subprocess pg_dump/pg_restore | 600 с | `backups.process_timeout` |
| job timeout | 900 с | `backups.job_timeout` |
| queue retry_after (connection `backups`) | 1200 с | `BACKUP_QUEUE_RETRY_AFTER` |
| Supervisor stopwaitsecs | 950 с | см. пример |
| worker max-time | 3300 с | см. пример |
| stale running | 1800 с | `backups.stale_after` |

Инвариант: `S3 request timeout < process_timeout < job_timeout <
retry_after`; `stopwaitsecs > job_timeout`, чтобы graceful-stop не убивал
копию посреди загрузки. Ошибочная конфигурация (например, request
timeout больше job_timeout) не отклоняется кодом — согласуйте значения
на сервере по таблице.

### Права на файлы

Временные дампы и pgpass создаются в
`storage/framework/backups-tmp/<uuid>/` (0700 директория, 0600 файлы).
`umask` SSH-сессии не меняется. После успеха и обрабатываемых ошибок
временные файлы убираются; после аварийного завершения — командой
`backups:cleanup-tmp` (часовой cron scheduler).

## Ручной запуск

- Кнопка «Создать копию» в суперадминке (`/admin-root/backups`) — только
  для ROOT; ставит job в очередь, не запускает `pg_dump` в HTTP-запросе.
  Кнопка «Обновить статус» перечитывает страницу; пока задача активна,
  статус также обновляется автоматически каждые 5 секунд.
- `php artisan backups:run` — то же из CLI.
- Ручной запуск и его результат пишутся в аудит
  (`backup.requested` / `backup.succeeded` / `backup.failed`).

## Проверка и скачивание объекта без Laravel

Объекты по умолчанию лежат под `irsi-app/{APP_ENV}/…`, например
`irsi-app/production/scheduled/2026/10/26/<uuid>.dump`.

### Timeweb S3 (path-style endpoint)

Не храните секреты в истории shell. Используйте env-файл или
`read -s`:

```bash
# ~/.irsi-backups-s3.env (chmod 600) — только на рабочей машине оператора
export AWS_ACCESS_KEY_ID='<ключ>'
export AWS_SECRET_ACCESS_KEY='<секрет>'
export AWS_DEFAULT_REGION=ru-1
export AWS_ENDPOINT_URL=https://s3.twcstorage.ru
export AWS_USE_PATHSTYLE_ENDPOINT=true
```

```bash
set -a; source ~/.irsi-backups-s3.env; set +a

# Список (AWS CLI v2 с endpoint)
aws s3 ls s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/ \
  --endpoint-url "$AWS_ENDPOINT_URL" --recursive

aws s3 cp s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<uuid>.dump \
  . --endpoint-url "$AWS_ENDPOINT_URL"
aws s3 cp s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<uuid>.dump.manifest.json \
  . --endpoint-url "$AWS_ENDPOINT_URL"
```

Или `s3cmd`:

```bash
s3cmd --host=s3.twcstorage.ru --host-bucket='%(bucket)s.s3.twcstorage.ru' \
  --access_key=... --secret_key=... \
  get s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<uuid>.dump .
```

(Ключи лучше передать через `--access_key` из защищённого файла, не
из shell history.)

### Сверка

```bash
sha256sum <uuid>.dump
# сравнить с полем sha256 в <uuid>.dump.manifest.json

pg_restore --list <uuid>.dump | head
```

## Восстановление (только в НОВУЮ отдельную БД!)

Восстановление в production из этих инструкций по умолчанию не
выполняется. Категорически не выполнять `dropdb`/`createdb` для
production-БД и не использовать `--clean --if-exists` на живой базе.

```bash
# 1. Создать НОВУЮ пустую базу с обычным (не суперпользовательским)
#    владельцем — например, копией роли приложения без -s/-P:
#    от пользователя postgres:
createuser irsi_restore          # БЕЗ -s; пароль задать через панель/ALTER ROLE
createdb -O irsi_restore irsi_app_restore

# 2. Восстановить дамп в новую базу (PGPASSWORD — только из env/файла,
#    не в командной строке). --exit-on-error: любой error = ненулевой exit.
set +x
read -r -s -p 'PG password: ' PGPASSWORD; export PGPASSWORD; echo
pg_restore -h 127.0.0.1 -p 5432 -U irsi_restore \
  -d irsi_app_restore --no-owner --no-privileges --exit-on-error \
  <uuid>.dump
status=$?
unset PGPASSWORD
# Проверка результата: pg_restore --exit-on-error уже ненулевым кодом
# сигналит о сбое. Дополнительно:
psql -h 127.0.0.1 -U irsi_restore -d irsi_app_restore -c '\dt' >/dev/null
echo "pg_restore exit: $status"
test "$status" -eq 0

# 3. Сверить контрольную сумму ещё раз после переноса
sha256sum <uuid>.dump
```

После восстановления в новую БД при необходимости переключите тестовый
стенд на неё (`DB_DATABASE=irsi_app_restore`) и отдельно перенесите
`.env` (в т.ч. `APP_KEY`), роли PostgreSQL и объекты аватаров.

## Гарантии восстановления состояний и очистки объектов

Что **гарантируется**:

- атомарная постановка (копия+job) при database-очереди на одном
  соединении;
- при другой очереди/соединении: сбой dispatch → `failed`, не вечный
  `queued`; потерянный `queued` без job → повторная постановка или
  `failed` через `backups:recover`;
- `running` старше `stale_after` → `failed` + уведомление ROOT +
  удаление незавершённых объектов этого запуска (при доступной БД/S3);
- success выставляется только после полной сверки dump+manifest;
- запоздавший исполнитель (после перехвата) не меняет результат и не
  запускает ротацию (`execution_token`);
- санитизированные тексты ошибок в БД, failed_jobs, логах воркера
  (без пароля БД и S3-секрета из конфигурации).

Что **не гарантируется** (ограничения):

- SIGKILL воркера до записи `failed`: строка остаётся `running` до
  `backups:recover`/`stale_after` (до ~30 минут по умолчанию); уведомление
  ROOT уходит при восстановлении, не мгновенно;
- при недоступной БД database-уведомление ROOT невозможно — остаётся
  только системный лог и stderr воркера;
- `queued` с job, но **выключенным** воркером может ждать сколько угодно
  (это ожидание живого воркера, не потеря);
- одновременный `delete` нескольких ключей не атомарен: частичная
  очистка возможна, повтор — через следующую ротацию/recovery;
- реальный `pg_dump`/`pg_restore` в тестах не выполняется (fake runner).

## Поведение при сбоях

- **Сбой между загрузкой дампа и manifest / записью успеха в БД**:
  ключи уже записаны как намерение; `run()`/`failed()`/`recover` удаляют
  незавершённые объекты этого запуска. Повторная доставка job
  идемпотентна: success/failed — no-op, свежий running не трогается.
- **Сбой БД во время записи ошибки**: сообщение уходит как минимум в
  системный лог (`storage/logs`) и в stderr воркера (Supervisor-лог);
  database-уведомление ROOT при недоступной БД не обещается.
- **Уведомления**: при сбое копии, прерывании stale и потере задания
  всем ROOT-пользователям создаётся database-уведомление (повторно —
  только при новом переходе статуса, без спама). Email/MAX/VK не
  используются; в текстах нет секретов.

## Ограничения v1

- Восстановление из суперадмина не запускается (только вручную по
  инструкции выше, в новую БД).
- Публичных ссылок и скачивания дампа из браузера нет.
- Реальный `pg_dump`/`pg_restore` в тестах не выполняется (fake runner);
  приёмка на живом PostgreSQL 16 — отдельная ручная проверка.
