#!/bin/sh
set -e

FORCE_FLAG=""
if [ "$APP_ENV" != "local" ]; then
    FORCE_FLAG="--force"
fi

echo "[entrypoint] Running Laravel migrations..."
php /var/www/html/artisan migrate --isolated $FORCE_FLAG

echo "[entrypoint] Running ClickHouse migrations..."
php /var/www/html/artisan clickhouse:migrate $FORCE_FLAG

echo "[entrypoint] Optimizing application..."
php /var/www/html/artisan optimize -e views
