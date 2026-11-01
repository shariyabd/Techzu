#!/bin/sh
set -e

cd /var/www/html

as_app() {
    if [ "$(id -u)" = "0" ]; then
        runuser -u www-data -- "$@"
    else
        "$@"
    fi
}

prepare() {
    if [ ! -f .env ]; then
        echo "techzu: .env is missing. Run: cp .env.docker.example .env" >&2
        exit 1
    fi

    if ! grep -Eq '^APP_KEY=.+' .env; then
        echo "techzu: generating APP_KEY in .env"
        php artisan key:generate --force --no-interaction
    fi

    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/testing storage/framework/views \
        storage/logs bootstrap/cache

    if [ "$(id -u)" = "0" ]; then
        chown -R www-data:www-data storage bootstrap/cache
    fi
}

wait_for_database() {
    attempts=0
    until as_app php artisan db:show >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "techzu: the database is not reachable. Check DB_* in .env." >&2
            as_app php artisan db:show
            exit 1
        fi
        echo "techzu: waiting for the database ($attempts/60)"
        sleep 2
    done
}

migrate() {
    if as_app php artisan migrate:status --no-interaction >/dev/null 2>&1; then
        as_app php artisan migrate --force --no-interaction
    else
        echo "techzu: first start, creating the tables and the demo data (this takes a few minutes)"
        as_app php artisan migrate --seed --force --no-interaction
    fi
}

if [ "$1" = "init" ]; then
    prepare
    wait_for_database
    migrate
    echo "techzu: ready"
    exit 0
fi

if [ "$1" = "php-fpm" ]; then
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
fi

exec "$@"
