#!/bin/bash
# =============================================================================
# Скрипт инициализации чистого VPS (Ubuntu 22.04/24.04)
# Запуск: sudo bash setup.sh
# =============================================================================

set -euo pipefail

echo "=== Начало настройки сервера для irsi-app.ru ==="

# ─── Обновление системы ───
echo "[1/9] Обновление пакетов..."
apt update && apt upgrade -y

# ─── Базовые утилиты ───
echo "[2/9] Установка базовых утилит..."
apt install -y curl git unzip supervisor redis-server software-properties-common apt-transport-https ca-certificates gnupg lsb-release

# ─── PHP 8.4 (ondrej/php PPA) ───
echo "[3/9] Установка PHP 8.4..."
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y php8.4-fpm php8.4-pgsql php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-redis php8.4-dom php8.4-tokenizer

# ─── PostgreSQL ───
echo "[4/9] Установка PostgreSQL..."
apt install -y postgresql postgresql-contrib

# ─── Node.js 20.x (NodeSource) ───
echo "[5/9] Установка Node.js 20.x..."
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# ─── Nginx ───
echo "[6/9] Установка Nginx..."
apt install -y nginx

# ─── Composer ───
echo "[7/9] Установка Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ─── Certbot (для HTTPS) ───
echo "[8/9] Установка Certbot..."
apt install -y certbot python3-certbot-nginx

# ─── Настройка Supervisor (для очередей) ───
echo "[9/9] Настройка Supervisor..."
systemctl enable supervisor
systemctl start supervisor

# ─── Завершение ───
echo ""
echo "=== Установка пакетов завершена ==="
echo "PHP:      $(php -v | head -1)"
echo "Node:     $(node -v)"
echo "NPM:      $(npm -v)"
echo "Postgres: $(psql --version)"
echo "Nginx:    $(nginx -v 2>&1)"
echo "Composer: $(composer -V | head -1)"
echo ""
echo "Следующий шаг: создайте базу данных и пользователя PostgreSQL,"
echo "затем скопируйте nginx.conf и запустите deploy.sh"
