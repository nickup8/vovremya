#!/bin/bash
# =============================================================================
# Скрипт деплоя обновлений для irsi-app.ru
# Запуск: sudo bash deploy.sh
# =============================================================================

set -euo pipefail

APP_DIR="/var/www/irsi-app"

echo "=== Начало деплоя irsi-app.ru ==="

# Переход в директорию проекта
cd "$APP_DIR"

# ─── Режим обслуживания ───
echo "[1/8] Включение режима обслуживания..."
php artisan down --render="errors::503" --retry=60

# ─── Получение последних изменений ───
echo "[2/8] Pull из репозитория..."
git pull origin main

# ─── Установка PHP-зависимостей ───
echo "[3/8] Composer install..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ─── Установка JS-зависимостей и сборка ───
echo "[4/8] npm install && npm run build..."
npm ci
npm run build

# ─── Миграции базы данных ───
echo "[5/8] Миграции..."
php artisan migrate --force

# ─── Очистка и кеширование ───
echo "[6/8] Кеширование конфигурации..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ─── Рестарт сервисов ───
echo "[7/8] Рестарт очередей и PHP-FPM..."
php artisan queue:restart
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx

# ─── Вывод из режима обслуживания ───
echo "[8/8] Отключение режима обслуживания..."
php artisan up

echo ""
echo "=== Деплой irsi-app.ru завершён успешно ==="
