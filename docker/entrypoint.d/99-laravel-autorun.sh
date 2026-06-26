#!/bin/sh
set -e

# Worker nodes share the main app image but must NOT migrate on boot: schema/state
# is owned by the main node, and N worker containers booting would otherwise all
# race to migrate the central DB.
if [ "$NODE_TYPE" = "worker" ]; then
    echo "[entrypoint] Worker node: skipping migrations (owned by the main node)."
else
    FORCE_FLAG=""
    if [ "$APP_ENV" != "local" ]; then
        FORCE_FLAG="--force"
    fi

    echo "[entrypoint] Running Laravel migrations..."
    php /var/www/html/artisan migrate --isolated $FORCE_FLAG

    echo "[entrypoint] Running ClickHouse migrations..."
    php /var/www/html/artisan clickhouse:migrate $FORCE_FLAG
fi

echo "[entrypoint] Optimizing application..."
php /var/www/html/artisan optimize -e views
