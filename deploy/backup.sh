#!/usr/bin/env bash
# Бэкап xcar на сервере: дамп базы (формат custom, оглавление проверяется
# pg_restore --list) и архив тома storage (медиатека, письма, вложения).
#   backup.sh        # база и файлы (ночной таймер)
#   backup.sh db     # только база (перед миграциями)
# Дампы держим 14 дней, архивов файлов — три: они по гигабайтам.
set -euo pipefail
ROOT=/srv/xcar
BACKUPS=/var/backups/xcar
KEEP_DAYS=14
KEEP_FILE_ARCHIVES=3
WITH_FILES=1; [ "${1:-all}" = "db" ] && WITH_FILES=0
STAMP="$(date +%Y%m%d-%H%M%S)"

# .env — файл compose, не shell-скрипт: читаем ключами.
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/$2" | tail -1; }
DB_DATABASE="$(env_value DB_DATABASE .env.app)"; DB_USERNAME="$(env_value DB_USERNAME .env.app)"
test -n "$DB_DATABASE" -a -n "$DB_USERNAME" || { echo "в .env.app нет DB_DATABASE/DB_USERNAME" >&2; exit 1; }
mkdir -p "$BACKUPS"

db="$BACKUPS/db-$STAMP.dump"
echo "==> база $DB_DATABASE"
# Дамп и проверка оглавления — внутри контейнера: формату custom нужен seek,
# из конвейера pg_restore --list отвечает «did not find magic string».
docker exec xcar-postgres-1 sh -c "pg_dump -U '$DB_USERNAME' -d '$DB_DATABASE' -Fc --no-owner --no-privileges -f /tmp/backup.dump && pg_restore --list /tmp/backup.dump > /dev/null"
docker cp xcar-postgres-1:/tmp/backup.dump "$db"
docker exec xcar-postgres-1 rm -f /tmp/backup.dump
test -s "$db" || { echo "дамп пустой" >&2; exit 1; }
echo "    $(du -h "$db" | cut -f1)"

if [ "$WITH_FILES" = "1" ]; then
    files="$BACKUPS/storage-$STAMP.tgz"
    echo "==> том storage"
    docker run --rm -v xcar_storage:/data:ro -v "$BACKUPS":/out alpine tar -czf "/out/$(basename "$files")" -C /data app/media app/private 2>/dev/null || \
    docker run --rm -v xcar_storage:/data:ro -v "$BACKUPS":/out alpine tar -czf "/out/$(basename "$files")" -C /data .
    echo "    $(du -h "$files" | cut -f1)"
    ls -1t "$BACKUPS"/storage-*.tgz 2>/dev/null | tail -n +$((KEEP_FILE_ARCHIVES + 1)) | xargs -r rm -f
fi

find "$BACKUPS" -name 'db-*.dump' -mtime +"$KEEP_DAYS" -delete
echo "==> в $BACKUPS: $(ls "$BACKUPS" | wc -l) файлов, свободно $(df -h "$BACKUPS" | awk 'NR==2{print $4}')"
