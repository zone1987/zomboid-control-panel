#!/bin/sh
set -e

: "${APP_ENV:=prod}"
: "${MESSENGER_WORKERS:=1}"
: "${PHP_FPM_API_MAX_CHILDREN:=12}"
: "${PHP_FPM_SSE_MAX_CHILDREN:=8}"

# Derived from APP_PUBLIC_URL unless set: typing the same domain three
# times invites a mismatch, which breaks passkeys with no visible reason.
_host=$(printf '%s' "${APP_PUBLIC_URL:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/?].*$##' -e 's#.*@##' -e 's#:[0-9]*$##')

: "${SERVER_NAME:=${_host:-localhost}}"
: "${WEBAUTHN_RELYING_PARTY_ID:=${_host:-localhost}}"
: "${WEBAUTHN_RELYING_PARTY_NAME:=ZomboidControl}"

export APP_ENV MESSENGER_WORKERS PHP_FPM_API_MAX_CHILDREN PHP_FPM_SSE_MAX_CHILDREN
export SERVER_NAME WEBAUTHN_RELYING_PARTY_ID WEBAUTHN_RELYING_PARTY_NAME

_secret_dir=/app/var/secrets
mkdir -p "$_secret_dir"
chmod 700 "$_secret_dir"

# Built from the password in the shared volume, which the database
# container wrote. An explicit DATABASE_URL wins, for an external one.
if [ -z "${DATABASE_URL:-}" ]; then
    _password_file="$_secret_dir/database-password"

    if [ ! -s "$_password_file" ]; then
        _tries=0
        while [ ! -s "$_password_file" ]; do
            _tries=$((_tries + 1))
            if [ "$_tries" -ge 60 ]; then
                echo "FATAL: no database password appeared at $_password_file." >&2
                echo "The database container writes it on first start." >&2
                exit 1
            fi
            sleep 1
        done
    fi

    _password=$(cat "$_password_file")
    _db="${POSTGRES_DB:-zomboid}"
    _user="${POSTGRES_USER:-zomboid}"
    _host="${POSTGRES_HOST:-database}"
    _port="${POSTGRES_PORT:-5432}"

    DATABASE_URL="postgresql://${_user}:${_password}@${_host}:${_port}/${_db}?serverVersion=17&charset=utf8"
    export DATABASE_URL
fi

# Generated on first start when unset, then persisted in the volume.
generate_secret() {
    _name=$1
    _bytes=$2
    _file="$_secret_dir/$_name"

    if [ ! -s "$_file" ]; then
        _generated=$(od -An -tx1 -N"$_bytes" /dev/urandom | tr -d ' \n')
        printf '%s' "$_generated" > "$_file"
        chmod 600 "$_file"
    fi

    cat "$_file"
}

if [ -z "${APP_SECRET:-}" ]; then
    APP_SECRET=$(generate_secret app-secret 16)
    export APP_SECRET
fi

# Losing this costs the stored FTP and RCON passwords, so a generated
# one is shown under Settings -> Security to be copied somewhere safe.
if [ -z "${CREDENTIALS_ENCRYPTION_KEY:-}" ]; then
    CREDENTIALS_ENCRYPTION_KEY=$(generate_secret credentials-key 32)
    export CREDENTIALS_ENCRYPTION_KEY
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
