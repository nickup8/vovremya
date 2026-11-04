#!/bin/bash
# =============================================================================
# Скрипт деплоя обновлений для irsi-app.ru
# Запуск: sudo bash deploy.sh
# =============================================================================

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/irsi-app}"
SSR_PROCESS="${SSR_PROCESS:-irsi-ssr}"

# Таймауты и повторы проверок (значения по умолчанию — для production).
SSR_CHECK_RETRIES="${SSR_CHECK_RETRIES:-3}"
SSR_CHECK_DELAY="${SSR_CHECK_DELAY:-2}"
SSR_CHECK_TIMEOUT="${SSR_CHECK_TIMEOUT:-30}"
PUBLIC_CHECK_RETRIES="${PUBLIC_CHECK_RETRIES:-3}"
PUBLIC_CHECK_DELAY="${PUBLIC_CHECK_DELAY:-3}"
PUBLIC_CHECK_TIMEOUT="${PUBLIC_CHECK_TIMEOUT:-20}"

# Ожидаемые признаки SSR гостевой главной — см. resources/js/pages/welcome-v2.tsx.
EXPECTED_H1="Онлайн-запись"
EXPECTED_CANONICAL="https://irsi-app.ru/"

# Проверка публичного HTML через DOM-парсер (ext-dom есть в setup.sh).
# Первичен разбор структуры, а не подстроки по всему документу.
SSR_VALIDATE_PHP="$(cat <<'PHP'
libxml_use_internal_errors(true);
$expectedH1 = getenv('EXPECTED_H1') ?: '';
$expectedCanonical = getenv('EXPECTED_CANONICAL') ?: '';
if ($expectedH1 === '' || $expectedCanonical === '') {
    echo 'не заданы ожидаемые значения H1/canonical';
    exit(1);
}
$path = $argv[1] ?? '';
$html = @file_get_contents($path);
if ($html === false || $html === '') {
    echo 'пустой или нечитаемый HTTP-ответ';
    exit(1);
}
$dom = new DOMDocument();
// Префикс фиксирует UTF-8 независимо от meta charset в ответе.
if (!@$dom->loadHTML('<?xml encoding="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
    echo 'ответ не разбирается как HTML';
    exit(1);
}
$xpath = new DOMXPath($dom);
$apps = $xpath->query('//*[@id="app"]');
$nApps = $apps === false ? 0 : $apps->length;
if ($nApps !== 1) {
    echo "ожидался ровно один элемент #app, найдено: {$nApps}";
    exit(1);
}
$app = $apps->item(0);
if ($app->getAttribute('data-server-rendered') !== 'true') {
    echo 'CSR-fallback: в #app нет data-server-rendered="true"';
    exit(1);
}
$nonempty = false;
foreach ($app->childNodes as $child) {
    if ($child instanceof DOMElement) {
        $tag = strtolower($child->tagName);
        // script/style/template — не серверный контент страницы.
        if (!in_array($tag, ['script', 'style', 'template'], true)) {
            $nonempty = true;
            break;
        }
    }
    if ($child instanceof DOMText && trim($child->nodeValue) !== '') {
        $nonempty = true;
        break;
    }
}
if (!$nonempty) {
    echo 'пустой серверный контент в #app (H1 вне корня не считается)';
    exit(1);
}
$foundH1 = false;
$h1s = $xpath->query('.//h1', $app);
if ($h1s !== false) {
    foreach ($h1s as $h1) {
        // script/style/template внутри H1 — не текст страницы (textContent
        // включал бы их содержимое, например JSON с ожидаемой строкой).
        $visible = $h1->cloneNode(true);
        $hidden = $xpath->query('.//script | .//style | .//template', $visible);
        if ($hidden !== false) {
            foreach ($hidden as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        $raw = $visible->textContent;
        $text = preg_replace('/\s+/u', ' ', $raw);
        if ($text === null) {
            $text = $raw;
        }
        if (strpos($text, $expectedH1) !== false) {
            $foundH1 = true;
            break;
        }
    }
}
if (!$foundH1) {
    echo "в H1 внутри #app нет ожидаемого текста «{$expectedH1}» (текст вне H1, в script/JSON и в script/style/template внутри H1 не считается)";
    exit(1);
}
$allCanonical = $xpath->query('//link[@rel="canonical"]');
$nAll = $allCanonical === false ? 0 : $allCanonical->length;
$inHead = $xpath->query('//head//link[@rel="canonical"]');
$nHead = $inHead === false ? 0 : $inHead->length;
if ($nAll !== 1 || $nHead !== 1) {
    echo "canonical: ожидаился ровно один и внутри head, найдено всего {$nAll}, в head {$nHead} (canonical вне head и дубли не принимаются)";
    exit(1);
}
$href = $inHead->item(0)->getAttribute('href');
if ($href !== $expectedCanonical) {
    echo "canonical href «{$href}» не равен ожидаемому «{$expectedCanonical}» (подстрока не принимается)";
    exit(1);
}
exit(0);
PHP
)"

fail() {
    echo "ОШИБКА: $*" >&2
}

# Любая необработанная ошибка прерывает выкладку с ненулевым кодом и без
# сообщения об успехе. В обработчике нет artisan up: снятие режима
# обслуживания выполняется только после успешных проверок.
on_unexpected_error() {
    local code=$?
    fail "Выкладка irsi-app.ru прервана (exit ${code}) — успешная выкладка НЕ подтверждена."
    fail "Приложение могло остаться в режиме обслуживания; снимайте его только после успешной проверки."
    exit "$code"
}
trap on_unexpected_error ERR

# ─── Пути артефактов сборки по фактической конфигурации ───
# laravel-vite-plugin: buildDirectory (по умолчанию build) → public/<dir>/manifest.json
resolve_client_manifest() {
    local build_dir="build" parsed=""
    if [[ -f vite.config.ts ]]; then
        parsed="$(sed -nE "s/.*buildDirectory:[[:space:]]*'([^']+)'.*/\1/p" vite.config.ts | head -n1)" || true
        if [[ -n "$parsed" ]]; then
            build_dir="$parsed"
        fi
    fi
    echo "public/${build_dir}/manifest.json"
}

# Ожидаемый SSR-бандл: явный 'bundle' в config/inertia.php, иначе выход
# vite build --ssr для inertia ssr entry из vite.config.ts.
resolve_ssr_bundle() {
    local configured="" entry="" name="ssr"
    if [[ -f config/inertia.php ]]; then
        configured="$(sed -nE "s/^[[:space:]]*'bundle'[[:space:]]*=>[[:space:]]*base_path\('([^']+)'\).*/\1/p" config/inertia.php | head -n1)" || true
    fi
    if [[ -n "$configured" ]]; then
        echo "$configured"
        return 0
    fi
    if [[ -f vite.config.ts ]]; then
        entry="$(sed -nE "s/.*entry:[[:space:]]*'([^']+)'.*/\1/p" vite.config.ts | head -n1)" || true
    fi
    if [[ -n "$entry" ]]; then
        name="$(basename "$entry")"
        name="${name%.*}"
    fi
    echo "bootstrap/ssr/${name}.js"
}

public_home_url() {
    # Публичная гостевая главная: canonical origin из welcome-v2.
    # APP_URL из .env не используется, чтобы не зависеть от его
    # настроек и не выводить содержимое .env в логи.
    if [[ -n "${PUBLIC_HOME_URL:-}" ]]; then
        echo "$PUBLIC_HOME_URL"
        return 0
    fi
    echo "https://irsi-app.ru/"
}

# Настоящий SSR, а не CSR-fallback: DOM-разбор ответа — непустой серверный
# контент внутри #app, ожидаемый H1 именно внутри него, ровно один canonical
# в head со строгим равенством href. Порядок атрибутов корня не важен.
validate_ssr_html() {
    local file="$1" reason
    if reason="$(EXPECTED_H1="$EXPECTED_H1" EXPECTED_CANONICAL="$EXPECTED_CANONICAL" \
        php -r "$SSR_VALIDATE_PHP" "$file" 2>&1)"; then
        return 0
    fi
    echo "${reason:-ошибка разбора HTML}"
    return 1
}

check_public_ssr() {
    local url code body attempt reason
    url="$(public_home_url)"
    body="$(mktemp)" || return 1
    for ((attempt = 1; attempt <= PUBLIC_CHECK_RETRIES; attempt++)); do
        code=""
        if ! code="$(curl -sS \
            --max-time "$PUBLIC_CHECK_TIMEOUT" \
            --connect-timeout "$PUBLIC_CHECK_TIMEOUT" \
            -o "$body" -w '%{http_code}' \
            "$url")"; then
            code=""
        fi
        if [[ "$code" == "200" ]]; then
            if reason="$(validate_ssr_html "$body")"; then
                rm -f "$body"
                echo "Публичная SSR-проверка пройдена: $url"
                return 0
            fi
        else
            reason="HTTP ${code:-нет ответа}"
        fi
        echo "Публичная проверка ($attempt/$PUBLIC_CHECK_RETRIES): $reason"
        if ((attempt < PUBLIC_CHECK_RETRIES)); then
            sleep "$PUBLIC_CHECK_DELAY"
        fi
    done
    rm -f "$body"
    return 1
}

echo "=== Начало деплоя irsi-app.ru ==="

# Переход в директорию проекта
cd "$APP_DIR"

# ─── Режим обслуживания ───
echo "[1/9] Включение режима обслуживания..."
php artisan down --render="errors::503" --retry=60

# ─── Получение последних изменений ───
echo "[2/9] Pull из репозитория..."
git pull --ff-only origin main

# ─── APP_VERSION (версия из VERSION) и APP_BUILD_SHA (git SHA) ───
APP_VERSION_VALUE="$(tr -d '[:space:]' < "$APP_DIR/VERSION")"
APP_BUILD_SHA="$(git rev-parse HEAD)"
if grep -q '^APP_VERSION=' "$APP_DIR/.env" 2>/dev/null; then
  sed -i "s|^APP_VERSION=.*|APP_VERSION=${APP_VERSION_VALUE}|" "$APP_DIR/.env"
else
  printf '\nAPP_VERSION=%s\n' "$APP_VERSION_VALUE" >> "$APP_DIR/.env"
fi
if grep -q '^APP_BUILD_SHA=' "$APP_DIR/.env" 2>/dev/null; then
  sed -i "s|^APP_BUILD_SHA=.*|APP_BUILD_SHA=${APP_BUILD_SHA}|" "$APP_DIR/.env"
else
  printf '\nAPP_BUILD_SHA=%s\n' "$APP_BUILD_SHA" >> "$APP_DIR/.env"
fi
echo "APP_VERSION=${APP_VERSION_VALUE} APP_BUILD_SHA=${APP_BUILD_SHA}"

# ─── Установка PHP-зависимостей ───
echo "[3/9] Composer install..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ─── Установка JS-зависимостей и сборка (клиент + SSR) ───
echo "[4/9] npm ci && npm run build:ssr..."
npm ci
npm run build:ssr

# Перед перезапуском — наличие и непустота артефактов по фактической конфигурации.
CLIENT_MANIFEST="$(resolve_client_manifest)"
SSR_BUNDLE="$(resolve_ssr_bundle)"
if [[ ! -s "$SSR_BUNDLE" ]]; then
    fail "SSR-бандл отсутствует или пуст: $SSR_BUNDLE"
    fail "См. docs/SSR_RECOVERY.md (восстановление согласованных сборок)."
    exit 1
fi
if [[ ! -s "$CLIENT_MANIFEST" ]]; then
    fail "Клиентский manifest отсутствует или пуст: $CLIENT_MANIFEST"
    fail "См. docs/SSR_RECOVERY.md (восстановление согласованных сборок)."
    exit 1
fi
echo "Артефакты сборки в порядке: $SSR_BUNDLE, $CLIENT_MANIFEST"

# ─── Миграции базы данных ───
echo "[5/9] Миграции..."
php artisan migrate --force

# ─── Очистка и кеширование ───
echo "[6/9] Кеширование конфигурации..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ─── Рестарт сервисов и адресный перезапуск SSR ───
echo "[7/9] Рестарт очередей, PHP-FPM и SSR ($SSR_PROCESS)..."
php artisan queue:restart
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx
sudo supervisorctl restart "$SSR_PROCESS"

# Проверка процесса SSR — до снятия режима обслуживания.
echo "Проверка SSR-процесса: php artisan inertia:check-ssr..."
ssr_healthy=0
for ((attempt = 1; attempt <= SSR_CHECK_RETRIES; attempt++)); do
    if timeout "$SSR_CHECK_TIMEOUT" php artisan inertia:check-ssr; then
        ssr_healthy=1
        break
    fi
    echo "Проверка SSR не прошла (попытка $attempt/$SSR_CHECK_RETRIES)."
    if ((attempt < SSR_CHECK_RETRIES)); then
        sleep "$SSR_CHECK_DELAY"
    fi
done
if [[ "$ssr_healthy" -ne 1 ]]; then
    fail "inertia:check-ssr не прошёл после перезапуска $SSR_PROCESS."
    fail "Режим обслуживания сохранён. Следующий шаг: docs/SSR_RECOVERY.md (восстановление SSR) и повтор выкладки."
    exit 1
fi

# ─── Вывод из режима обслуживания ───
echo "[8/9] Отключение режима обслуживания..."
php artisan up

# ─── Итоговая публичная SSR-проверка гостевой главной ───
echo "[9/9] Публичная SSR-проверка гостевой главной..."
if ! check_public_ssr; then
    fail "Публичная SSR-проверка не прошла — возвращаю режим обслуживания."
    if ! php artisan down --render="errors::503" --retry=60; then
        fail "Не удалось вернуть режим обслуживания — выполните artisan down вручную."
    fi
    fail "Следующий шаг: восстановить согласованные клиентскую и SSR-сборки и перезапустить $SSR_PROCESS (docs/SSR_RECOVERY.md), затем повторить выкладку."
    exit 1
fi

echo ""
echo "=== Деплой irsi-app.ru завершён успешно ==="
