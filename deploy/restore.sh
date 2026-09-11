#!/usr/bin/env bash
# Восстановление базы из дампа: restore.sh /var/backups/xcar/db-….dump
# Приложение на время останавливается, база пересоздаётся.
set -euo pipefail
DUMP="${1:?путь к дампу}"; ROOT=/srv/xcar
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/.env.app" | tail -1; }
DB="$(env_value DB_DATABASE)"; USER="$(env_value DB_USERNAME)"
cd "$ROOT/current/deploy"
docker compose stop app queue queue-long scheduler mail-watch
docker exec xcar-postgres-1 psql -U "$USER" -d postgres -c "DROP DATABASE IF EXISTS \"$DB\";" -c "CREATE DATABASE \"$DB\";"
docker exec -i xcar-postgres-1 pg_restore -U "$USER" -d "$DB" --no-owner --no-privileges < "$DUMP"
docker compose up -d
echo "восстановлено из $DUMP"
