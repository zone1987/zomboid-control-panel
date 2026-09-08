#!/usr/bin/env bash
#
# Proves that `zomboidcontrol:candidate` starts and answers, in the two
# ways it is actually deployed.
#
#   configured    every secret supplied, which is the least likely way
#                 anybody deploys but the one that isolates a fault
#   unconfigured  a domain and nothing else, which is what the README
#                 documents: the passwords are generated inside, and
#                 they have to survive a restart
#
set -euo pipefail

MODE="${1:?usage: start-image.sh configured|unconfigured}"
IMAGE="${IMAGE:-zomboidcontrol:candidate}"
PORT="${PORT:-8099}"

wait_for_health() {
  local status=missing

  for _ in $(seq 1 40); do
    status=$(docker inspect --format='{{.State.Health.Status}}' ci-app 2>/dev/null || echo missing)
    [ "$status" = "healthy" ] && return 0
    [ "$status" = "unhealthy" ] && break
    sleep 3
  done

  echo "::error::The image did not become healthy ($MODE); last status: $status"
  docker logs ci-app
  return 1
}

reset() {
  docker rm -f ci-app ci-db >/dev/null 2>&1 || true
  docker network create ci-net >/dev/null 2>&1 || true
}

case "$MODE" in
  configured)
    reset

    docker run -d --name ci-db --network ci-net \
      -e POSTGRES_DB=zomboid -e POSTGRES_USER=zomboid \
      -e POSTGRES_PASSWORD=ci-password \
      postgres:17-alpine

    docker run -d --name ci-app --network ci-net -p "$PORT:80" \
      -e APP_SECRET=0123456789abcdef0123456789abcdef \
      -e CREDENTIALS_ENCRYPTION_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
      -e "DATABASE_URL=postgresql://zomboid:ci-password@ci-db:5432/zomboid?serverVersion=17&charset=utf8" \
      -e MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=0' \
      -e "APP_PUBLIC_URL=http://localhost:$PORT" \
      -e WEBAUTHN_RELYING_PARTY_ID=localhost \
      "$IMAGE"

    wait_for_health

    test "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/api/health")" = 200
    test "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/app/")" = 200
    test "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/app/servers")" = 200
    curl -s "http://localhost:$PORT/api/health" | grep -q '"database":"ok"'

    # Every supervised process must be RUNNING; a crash loop would
    # otherwise pass unnoticed behind a healthy HTTP response.
    docker exec ci-app supervisorctl status > procs.txt
    cat procs.txt
    total=$(grep -c . procs.txt)
    running=$(grep -c RUNNING procs.txt)
    test "$total" -eq "$running"
    test "$total" -ge 4
    ;;

  unconfigured)
    reset
    docker volume rm -f ci-secrets >/dev/null 2>&1 || true
    docker volume create ci-secrets >/dev/null

    docker run -d --name ci-db --network ci-net \
      -e POSTGRES_DB=zomboid -e POSTGRES_USER=zomboid \
      -e POSTGRES_PASSWORD_FILE=/run/secrets/database-password \
      -v ci-secrets:/run/secrets \
      --entrypoint sh postgres:17-alpine -c '
        set -e
        mkdir -p /run/secrets
        if [ ! -s /run/secrets/database-password ]; then
          tr -dc "A-Za-z0-9" < /dev/urandom | head -c 48 > /run/secrets/database-password
        fi
        chmod 600 /run/secrets/database-password
        exec docker-entrypoint.sh postgres'

    docker run -d --name ci-app --network ci-net -p "$PORT:80" \
      -e "APP_PUBLIC_URL=http://localhost:$PORT" \
      -e POSTGRES_HOST=ci-db \
      -v ci-secrets:/app/var/secrets \
      "$IMAGE"

    wait_for_health

    test "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/api/health")" = 200
    curl -s "http://localhost:$PORT/api/health" | grep -q '"database":"ok"'

    # Generated rather than demanded, and each the right length.
    test "$(docker exec ci-app sh -c 'wc -c < /app/var/secrets/app-secret')" -eq 32
    test "$(docker exec ci-app sh -c 'wc -c < /app/var/secrets/credentials-key')" -eq 64

    before=$(docker exec ci-app cat /app/var/secrets/credentials-key)
    docker restart ci-app >/dev/null
    wait_for_health
    after=$(docker exec ci-app cat /app/var/secrets/credentials-key)

    test "$before" = "$after"
    echo "Secrets survived a restart."
    ;;

  *)
    echo "::error::Unknown mode: $MODE" >&2
    exit 1
    ;;
esac
