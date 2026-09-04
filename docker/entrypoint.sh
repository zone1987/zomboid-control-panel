#!/bin/sh
set -e

: "${APP_ENV:=prod}"
: "${MESSENGER_WORKERS:=1}"
: "${PHP_FPM_API_MAX_CHILDREN:=12}"
: "${PHP_FPM_SSE_MAX_CHILDREN:=8}"
: "${SERVER_NAME:=localhost}"
export APP_ENV MESSENGER_WORKERS PHP_FPM_API_MAX_CHILDREN PHP_FPM_SSE_MAX_CHILDREN SERVER_NAME

if [ -z "${APP_SECRET:-}" ]; then
    echo "FATAL: APP_SECRET is not set." >&2
    exit 1
fi

if [ -z "${CREDENTIALS_ENCRYPTION_KEY:-}" ]; then
    echo "FATAL: CREDENTIALS_ENCRYPTION_KEY is not set." >&2
    echo "Generate one with: openssl rand -hex 32" >&2
    exit 1
fi

if [ "${#CREDENTIALS_ENCRYPTION_KEY}" -ne 64 ]; then
    echo "FATAL: CREDENTIALS_ENCRYPTION_KEY must be 64 hex characters, got ${#CREDENTIALS_ENCRYPTION_KEY}." >&2
    exit 1
fi

echo "Waiting for the database..."
_tries=0
until php /app/bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
    _tries=$((_tries + 1))
    if [ "$_tries" -ge 60 ]; then
        echo "FATAL: database did not become reachable within 60s." >&2
        exit 1
    fi
    sleep 1
done

echo "Applying migrations..."
php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

php /app/bin/console cache:warmup

exec "$@"
