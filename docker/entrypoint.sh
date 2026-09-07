#!/bin/sh
set -e

: "${APP_ENV:=prod}"
: "${MESSENGER_WORKERS:=1}"
: "${PHP_FPM_API_MAX_CHILDREN:=12}"
: "${PHP_FPM_SSE_MAX_CHILDREN:=8}"

# An unresolved template is not an address. `{{SERVICE_URL_APP}}` is
# Coolify's magic-variable syntax, which it substitutes for *services*
# and leaves untouched for an *application* -- so it arrives verbatim and
# every URL the panel builds from it is broken, silently.
unresolved() {
    case "$1" in
        *'{{'*|*'}}'*|'$'*|*'${'*) return 0 ;;
        *) return 1 ;;
    esac
}

if [ -n "${APP_PUBLIC_URL:-}" ] && unresolved "$APP_PUBLIC_URL"; then
    echo "WARNING: APP_PUBLIC_URL is an unresolved template: $APP_PUBLIC_URL" >&2
    echo "Coolify substitutes SERVICE_URL_* only for services, not for an" >&2
    echo "application. Leave APP_PUBLIC_URL empty to use COOLIFY_URL, or" >&2
    echo "set it to the address itself." >&2
    APP_PUBLIC_URL=""
fi

# Coolify puts the configured domain in COOLIFY_URL, so a deployment
# there needs nothing typed at all. It may hold several, comma separated
# -- the first is the one to build links from.
if [ -z "${APP_PUBLIC_URL:-}" ] && [ -n "${COOLIFY_URL:-}" ]; then
    APP_PUBLIC_URL=$(printf '%s' "$COOLIFY_URL" | cut -d, -f1 | tr -d ' ')

    if unresolved "$APP_PUBLIC_URL"; then
        echo "WARNING: COOLIFY_URL is an unresolved template too." >&2
        APP_PUBLIC_URL=""
    fi
fi

if [ -z "${APP_PUBLIC_URL:-}" ]; then
    echo "FATAL: no public address is set." >&2
    echo "Set APP_PUBLIC_URL to the address people type, e.g." >&2
    echo "  APP_PUBLIC_URL=https://zomboid.example.com" >&2
    echo "On Coolify, leave it empty and COOLIFY_URL is used instead --" >&2
    echo "add COOLIFY_URL as an environment variable with an empty value" >&2
    echo "if this message appears there." >&2
    exit 1
fi

echo "Public address: $APP_PUBLIC_URL"

export APP_PUBLIC_URL

# Derived from APP_PUBLIC_URL unless set: typing the same domain three
# times invites a mismatch, which breaks passkeys with no visible reason.
_host=$(printf '%s' "$APP_PUBLIC_URL" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/?].*$##' -e 's#.*@##' -e 's#:[0-9]*$##')

: "${SERVER_NAME:=${_host:-localhost}}"
: "${WEBAUTHN_RELYING_PARTY_ID:=${_host:-localhost}}"
: "${WEBAUTHN_RELYING_PARTY_NAME:=ZomboidControl}"

export APP_ENV MESSENGER_WORKERS PHP_FPM_API_MAX_CHILDREN PHP_FPM_SSE_MAX_CHILDREN
export SERVER_NAME WEBAUTHN_RELYING_PARTY_ID WEBAUTHN_RELYING_PARTY_NAME

_secret_dir=/app/var/secrets
mkdir -p "$_secret_dir"
chown www-data:www-data "$_secret_dir"
chmod 750 "$_secret_dir"

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
    fi

    # The panel reads these back as www-data to show the key.
    chown www-data:www-data "$_file"
    chmod 640 "$_file"

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
