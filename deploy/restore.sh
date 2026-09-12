#!/usr/bin/env bash
# Восстановление xcar.
#   restore.sh db /srv/xcar/data/backups/db-….dump   # база из локального дампа
#   restore.sh db latest                             # база из последнего снимка restic
#   restore.sh files [каталог]                       # media и private из последнего
#                                                    # снимка restic (по умолчанию —
#                                                    # на место, поверх текущих)
#   restore.sh snapshots                             # что есть в restic
# Приложение на время останавливается; база пересоздаётся.
set -euo pipefail
ROOT=/srv/xcar
DATA="${XCAR_DATA:-/srv/xcar/data}"
RESTIC_IMAGE=restic/restic:0.18.0
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/$2" | tail -1; }
DB="$(env_value DB_DATABASE .env.app)"; USER="$(env_value DB_USERNAME .env.app)"
for k in RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION; do
    export "$k=$(env_value $k .env)"
done

restic() { # $1 — каталог хоста, куда restic пишет (/out внутри)
    local out="$1"; shift
    docker run --rm -i --hostname xcar \
        -e RESTIC_REPOSITORY -e RESTIC_PASSWORD -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION \
        -v "$DATA/restic-cache:/root/.cache/restic" -v "$out:/out" \
        "$RESTIC_IMAGE" -o "s3.region=$AWS_DEFAULT_REGION" "$@"
}

stop_app()  { (cd "$ROOT/current/deploy" && docker compose stop app queue queue-long scheduler mail-watch); }
start_app() { (cd "$ROOT/current/deploy" && docker compose up -d); }

case "${1:-}" in
    db)
        dump="${2:?дамп или latest}"
        if [ "$dump" = latest ]; then
            tmp="$(mktemp -d)"
            restic "$tmp" restore latest --tag db --target /out
            dump="$tmp/db.dump"
        fi
        test -s "$dump" || { echo "дампа $dump нет" >&2; exit 1; }
        stop_app
        docker exec xcar-postgres-1 psql -U "$USER" -d postgres -c "DROP DATABASE IF EXISTS \"$DB\";" -c "CREATE DATABASE \"$DB\";"
        docker exec -i xcar-postgres-1 pg_restore -U "$USER" -d "$DB" --no-owner --no-privileges < "$dump"
        start_app
        echo "база восстановлена из $dump" ;;
    files)
        target="${2:-$DATA}"
        mkdir -p "$target"
        [ "$target" = "$DATA" ] && stop_app
        # В снимке пути /data/media и /data/private → в $target/media, $target/private.
        restic "$target" restore latest --tag files --target /out --include /data
        cp -a "$target/data/." "$target/" && rm -rf "$target/data"
        chown -R 33:33 "$target/media" "$target/private"
        if [ "$target" = "$DATA" ]; then
            start_app
            # Конверсий в снимке нет — досчитать недостающие в очереди.
            (cd "$ROOT/current/deploy" && docker compose exec app php artisan media-library:regenerate --only-missing)
        fi
        echo "файлы восстановлены в $target" ;;
    snapshots)
        restic /tmp snapshots ;;
    *) echo "restore.sh db <дамп|latest> | files [каталог] | snapshots" >&2; exit 2 ;;
esac
