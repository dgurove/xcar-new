#!/usr/bin/env bash
# Бэкап xcar на сервере.
#   backup.sh        # ночной таймер: дамп базы, снимки restic, ротация, логи в S3
#   backup.sh db     # только локальный дамп базы (перед миграциями)
#   backup.sh files  # только снимок файлов в restic
#   backup.sh logs   # только отправить вчерашние журналы в S3
#
# База — pg_dump в формате custom (оглавление проверяется pg_restore --list):
# семь дней локально на диске данных, чтобы откатиться за минуту, и тот же дамп
# снимком restic. Файлы — media без конверсий (они пересчитываются из
# оригиналов) и private — вторым снимком: restic пишет только новые блоки, так
# что фото уходят в S3 один раз. Ротация: 14 дневных, 8 недельных, 6 месячных;
# prune и проверка — по воскресеньям. Журналы контейнеров — по дню в
# logs/ГГГГ/ММ/ДД, старше 90 дней стираются. Ключи S3 и пароль репозитория —
# RESTIC_* и AWS_* в /srv/xcar/env/.env.
set -euo pipefail
ROOT=/srv/xcar
DATA="${XCAR_DATA:-/srv/xcar/data}"
BACKUPS="$DATA/backups"
KEEP_DAYS=7
RESTIC_IMAGE=restic/restic:0.18.0
RCLONE_IMAGE=rclone/rclone:1.69
STEP="${1:-all}"
STAMP="$(date +%Y%m%d-%H%M%S)"

# .env — файл compose, не shell-скрипт: читаем ключами.
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/$2" | tail -1; }
DB_DATABASE="$(env_value DB_DATABASE .env.app)"; DB_USERNAME="$(env_value DB_USERNAME .env.app)"
test -n "$DB_DATABASE" -a -n "$DB_USERNAME" || { echo "в .env.app нет DB_DATABASE/DB_USERNAME" >&2; exit 1; }
for k in RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION S3_ENDPOINT S3_BUCKET; do
    export "$k=$(env_value $k .env)"
done
mkdir -p "$BACKUPS" "$DATA/restic-cache"

restic() {
    docker run --rm -i --cpu-shares 256 --hostname xcar \
        -e RESTIC_REPOSITORY -e RESTIC_PASSWORD -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION \
        -v "$DATA/restic-cache:/root/.cache/restic" \
        -v "$DATA/media:/data/media:ro" -v "$DATA/private:/data/private:ro" \
        "$RESTIC_IMAGE" -o "s3.region=$AWS_DEFAULT_REGION" --quiet "$@"
}
have_s3() { [ -n "$RESTIC_REPOSITORY" ] && [ -n "$RESTIC_PASSWORD" ] && [ -n "$AWS_ACCESS_KEY_ID" ]; }

dump_db() {
    db="$BACKUPS/db-$STAMP.dump"
    echo "==> база $DB_DATABASE"
    # Дамп и проверка оглавления — внутри контейнера: формату custom нужен seek,
    # из конвейера pg_restore --list отвечает «did not find magic string».
    docker exec xcar-postgres-1 sh -c "pg_dump -U '$DB_USERNAME' -d '$DB_DATABASE' -Fc --no-owner --no-privileges -f /tmp/backup.dump && pg_restore --list /tmp/backup.dump > /dev/null"
    docker cp xcar-postgres-1:/tmp/backup.dump "$db"
    docker exec xcar-postgres-1 rm -f /tmp/backup.dump
    test -s "$db" || { echo "дамп пустой" >&2; exit 1; }
    echo "    $(du -h "$db" | cut -f1)"
    find "$BACKUPS" -name 'db-*.dump' -mtime +"$KEEP_DAYS" -delete
}

snapshot_db() {
    have_s3 || { echo "==> restic: ключей в .env нет, снимок базы пропущен"; return; }
    echo "==> restic: база"
    restic backup --stdin --stdin-filename db.dump --tag db < "$db"
}

snapshot_files() {
    have_s3 || { echo "==> restic: ключей в .env нет, снимок файлов пропущен"; return; }
    echo "==> restic: media и private"
    restic backup /data/media /data/private --tag files --exclude '**/conversions' --exclude-caches
    date +%s > "$BACKUPS/last-ok"
}

rotate() {
    have_s3 || return 0
    echo "==> restic: ротация"
    for tag in db files; do
        restic forget --tag "$tag" --group-by tags --keep-daily 14 --keep-weekly 8 --keep-monthly 6
    done
    if [ "$(date +%u)" = 7 ]; then
        restic prune --max-unused 10%
        restic check
    fi
}

ship_logs() {
    have_s3 || { echo "==> журналы: ключей в .env нет, пропущено"; return; }
    day="$(date -d yesterday +%Y/%m/%d)"
    tmp="$(mktemp -d)"; mkdir -p "$tmp/$day"
    echo "==> журналы за $day"
    for c in $(docker ps --format '{{.Names}}' | grep '^xcar-'); do
        docker logs -t --since 24h "$c" 2>&1 | gzip -1 > "$tmp/$day/${c#xcar-}.log.gz"
    done
    journalctl -u docker --since yesterday --until today --no-pager 2>/dev/null | gzip -1 > "$tmp/$day/docker.journal.gz"
    docker run --rm --entrypoint sh -v "$tmp:/logs:ro" \
        -e RCLONE_CONFIG_S3_TYPE=s3 -e RCLONE_CONFIG_S3_PROVIDER=Other \
        -e "RCLONE_CONFIG_S3_ACCESS_KEY_ID=$AWS_ACCESS_KEY_ID" -e "RCLONE_CONFIG_S3_SECRET_ACCESS_KEY=$AWS_SECRET_ACCESS_KEY" \
        -e "RCLONE_CONFIG_S3_ENDPOINT=$S3_ENDPOINT" -e "RCLONE_CONFIG_S3_REGION=$AWS_DEFAULT_REGION" \
        "$RCLONE_IMAGE" -c "rclone copy /logs 's3:$S3_BUCKET/logs' -q && rclone delete --min-age 90d 's3:$S3_BUCKET/logs' -q && rclone rmdirs --leave-root 's3:$S3_BUCKET/logs' -q"
    rm -rf "$tmp"
}

case "$STEP" in
    all)   dump_db; snapshot_db; snapshot_files; rotate; ship_logs ;;
    db)    dump_db ;;
    files) snapshot_files ;;
    logs)  ship_logs ;;
    *) echo "неизвестный шаг: $STEP" >&2; exit 2 ;;
esac
echo "==> локально в $BACKUPS: $(ls "$BACKUPS" | grep -c '^db-') дампов, свободно $(df -h "$DATA" | awk 'NR==2{print $4}')"
