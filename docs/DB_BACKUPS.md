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
  `backups.job_timeout`). Сериализуется UUID копии и токен доставки
  (создаётся при постановке, лежит в исходном payload).
- **Исполнение**: `pg_dump --format=custom` (без остановки приложения),
  аргументы — массивом, без shell. Пароль передаётся только через
  `PGPASSFILE` (файл 0600 во временной директории копии).
- **Верификация**: exit code `pg_dump`, ненулевой размер, читаемость
  архива через `pg_restore --list` (это **не** проверенное
  восстановление), потоковая загрузка в S3, затем повторное чтение
  объекта и сверка размера и SHA-256. Только после этого копия получает
  статус `success`.
- **Manifest**: рядом с дампом лежит `<object>.manifest.json` — id, время,
  размер, SHA-256, версия PostgreSQL, версия приложения
  (`app_version` — **пользовательский номер релиза**, например `1.1.0`,
  из файла `VERSION`; `app_build_sha` — **полный git SHA фактически
  развёрнутого коммита**, см. deploy.sh; при локальной разработке — `dev`).
  Для восстановления доступны и номер релиза, и точный коммит. Старые
  manifest (до разделения) содержат git SHA в `app_version` и поля
  `app_build_sha` не имеют — это допустимо, существующие операции их
  читают без изменений. Проверить копию можно без работающей Laravel-БД.
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
    уведомление ROOT и очистка незавершённых S3-объектов;
  - `cleanup_pending_at` — очистка не удалась → ограниченный повтор
    (`BACKUP_CLEANUP_RETRY_MAX`, по умолчанию 5).
  Вторая копия поверх живого процесса не запускается: `begin()` —
  атомарный CAS по `status` + `execution_token`; запоздавший исполнитель
  не может записать success/error чужого запуска и не запускает ротацию.
  Дубликат доставки job, проигравший гонку захвата, — no-op
  (`BackupCaptureLost`): failed()-хук прерывает только исполнение своей
  доставки (CAS по `execution_token`) и не помечает прерванной чужую
  живую попытку; при проигрыше CAS ни очистки, ни уведомления нет.
- **Токен доставки в payload**: `execution_token` создаётся в
  конструкторе job при постановке и попадает в сериализованный payload,
  поэтому `handle()` начинает исполнение тем токеном, что видит payload,
  а `failed()` — который Laravel вызывает на новом экземпляре,
  восстановленном из **исходного** payload'а, — тем же токеном.
  Разные доставки получают разные токены, поэтому поздний `failed()`
  одной доставки не прерывает исполнение другой; legacy-payload без
  токена закрывает генерацию токена в `handle()`.
- **Изоляция исполнений**: каждая попытка имеет свой
  `execution_token` (генерируется в job-доставке и лежит в нём же, чтобы
  failed()-хук знал своё исполнение). Временный каталог —
  `{tmp_dir}/{id}/{token}/`, ключи объектов —
  `…/{Y}/{m}/{d}/{token}/{id}.dump`: разные исполнения одного id не
  делят временные файлы и удаляемые объекты.
- **Учёт незавершённых объектов**: ключ попадает в БД как объект этого
  исполнения только после проверки, что он **свободен** (до HTTP-загрузки).
  Занятый ключ — отказ без удаления: намерение загрузки не даёт права
  удалять существующий объект. При сбое/прерывании удаляются только
  подтверждённые ключи этого исполнения в namespace
  `{prefix}/{APP_ENV}/{trigger}/…` с полным id копии в имени;
  `delete===false` считается ошибкой. Успешные копии и чужие ключи не
  затрагиваются; скан бакета не выполняется.
- **Очистка после сбоя delete**: если удаление не удалось, запись
  получает состояние ожидания очистки (`cleanup_pending_at`) **вместе с
  ключами и токеном-владельцем этого исполнения** (`cleanup_keys`,
  `cleanup_execution_token`); `backups:recover` повторяет очистку только
  по записанным ключам владельца, не больше `BACKUP_CLEANUP_RETRY_MAX`
  раз и никогда не берёт текущие `object_key`/`manifest_key` строки —
  строка могла уже принадлежать другому (например, успешному)
  исполнению. Поздний проигравший своё ожидание записывает (CAS по
  владельцу), но не затирает уже записанное чужое.
- **Ротация**: последние 7 успешных копий scheduled и последние 7
  успешных manual хранятся отдельно (`BACKUP_KEEP_SCHEDULED`,
  `BACKUP_KEEP_MANUAL`). Старые удаляются только после полной проверки
  новой копии; сбой очистки не делает новую копию невалидной
  (`prune_error_message`). Ротация отделена от завершения: исключение
  при входе в ротацию после `success` не вызывает очистку новой копии и
  не меняет её статус — ошибка пишется отдельно.
- **Ошибки после подтверждённого успеха**: как только UPDATE
  `status=success` подтверждён (CAS вернул 1), дальнейшие сбои — чтения
  строки, аудита и т.п. — не запускают destructive cleanup и не меняют
  статус: ошибка пишется отдельно (`prune_error_message`), объекты и SHA
  сохраняются. Если исход самой записи success **неизвестен** (UPDATE
  бросил исключение), потенциально успешные объекты тоже не удаляются —
  cleanup разрешён только при определённо не применившейся записи.

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

# Повторов очистки незавершённых объектов после сбоя S3 delete
BACKUP_CLEANUP_RETRY_MAX=5
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

5. `php artisan migrate` — создаёт таблицу `database_backups`,
   `execution_token` и колонки состояния очистки
   (`cleanup_pending_at`, `cleanup_attempts`).

6. Scheduler уже запускается root-cron; в `routes/console.php`
   зарегистрированы `backups:schedule-daily` (03:30 Europe/Moscow,
   срабатывает только при `BACKUP_SCHEDULED_ENABLED=true`),
   `backups:recover` (каждые 15 минут, всегда) и почасовая уборка
   временных файлов `backups:cleanup-tmp`.

7. **`APP_VERSION` и `APP_BUILD_SHA`** — deploy.sh после `git pull`
   пишет в серверный `.env`:
   - `APP_VERSION` — пользовательская версия релиза из отслеживаемого
     файла `VERSION` (единственный источник версии; SHA в `APP_VERSION`
     больше никогда не записывается);
   - `APP_BUILD_SHA=$(git rev-parse HEAD)` — полный git SHA
     фактически развёрнутого коммита.

   Записи идемпотентны (повторный запуск не создаёт дублей), другие
   значения `.env` не выводятся и не изменяются. Значения попадают в
   `.env` до `config:cache`. Политика semver для `VERSION` — см.
   [RELEASE_VERSIONING.md](RELEASE_VERSIONING.md).

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
`irsi-app/production/scheduled/2026/10/26/<execution_token>/<uuid>.dump`
(каталог по токену исполнения отделяет объекты разных попыток одного id).

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

aws s3 cp s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<token>/<uuid>.dump \
  . --endpoint-url "$AWS_ENDPOINT_URL"
aws s3 cp s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<token>/<uuid>.dump.manifest.json \
  . --endpoint-url "$AWS_ENDPOINT_URL"
```

Или `s3cmd` — с ключами **только** в защищённом конфиг-файле, не в
аргументах командной строки (аргументы видны в `ps` и попадают в
историю shell):

```bash
# ~/.s3cfg-backups (chmod 600): заполнить access_key/secret_key один раз
# [default]
# access_key = <ключ>
# secret_key = <секрет>
# host_base = s3.twcstorage.ru
# host_bucket = %(bucket)s.s3.twcstorage.ru
# use_https = True

s3cmd -c ~/.s3cfg-backups \
  get s3://irsi-db-backups/irsi-app/production/scheduled/2026/10/26/<token>/<uuid>.dump .
```

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
  CAS прерывания повторно проверяет и staleness, и наблюдаемый
  `execution_token` — перехваченный копии прерывание не грозит;
- success выставляется только после полной сверки dump+manifest; после
  подтверждённого success сбой ротации/очистки/аудита не вызывает
  удаление новой копии и не меняет её статус;
- запоздавший исполнитель (после перехвата) не меняет результат, не
  запускает ротацию и не удаляет файлы нового исполнения
  (`execution_token` + токен-скоуп ключей и временного каталога);
- failed()-хук job прерывает только исполнение своей доставки; при
  проигрыше CAS нет ни очистки, ни ложного уведомления о чужом сбое;
- занятый объект в бакете не удаляется и не перезаписывается: ключ
  становится объектом исполнения только после проверки, что он свободен;
- неуданная очистка незавершённых объектов повторяется
  `backups:recover` не более `BACKUP_CLEANUP_RETRY_MAX` раз и только по
  ключам, записанным в самой строке (бакет не сканируется);
- санитизированные тексты ошибок в БД, failed_jobs, логах воркера
  (без пароля БД и S3-секрета из конфигурации) — в том числе ошибки
  самой очистки.

Что **не гарантируется** (ограничения):

- SIGKILL воркера до записи `failed`: строка остаётся `running` до
  `backups:recover`/`stale_after` (до ~30 минут по умолчанию); уведомление
  ROOT уходит при восстановлении, не мгновенно;
- при недоступной БД database-уведомление ROOT невозможно — остаётся
  только системный лог и stderr воркера;
- `queued` с job, но **выключенным** воркером может ждать сколько угодно
  (это ожидание живого воркера, не потеря);
- одновременный `delete` нескольких ключей не атомарен: частичная
  очистка возможна; повтор ограничен `BACKUP_CLEANUP_RETRY_MAX`, после
  исчерпания попыток удаление остатков — вручную по ключам из строки
  (`object_key`/`manifest_key`);
- реальный `pg_dump`/`pg_restore` в тестах не выполняется (fake runner).

## Поведение при сбоях

- **Сбой между загрузкой дампа и manifest / записью успеха в БД**:
  свободные ключи уже записаны как объекты этого исполнения; `run()`
  удаляет их по своему токену, `failed()`/`recover` прерывают и чистят
  только своё исполнение (CAS по `execution_token`), неуданная очистка
  повторяется через `backups:recover`. Повторная доставка job
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
