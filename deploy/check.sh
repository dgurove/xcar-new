#!/usr/bin/env bash
# Проверка живости: страницы, контейнеры, оба диска, свежесть бэкапа, очередь.
# С --telegram шлёт сообщение при сбое (TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в
# /srv/xcar/env/.env); без него ещё печатает, чем заняты диски.
set -uo pipefail
ROOT=/srv/xcar
DATA="${XCAR_DATA:-/srv/xcar/data}"
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/.env" | tail -1; }
ADDRESSES="$(env_value SITE_ADDRESSES)"; ADDRESSES="${ADDRESSES:-http://127.0.0.1}"
problems=()

# Каждое имя: сайт отдаёт страницы, CRM и стоянка гостю — вход. По https на себя: на :80 Caddy отвечает 308,
# а прежние хосты (garage) — 301 на сайт; редиректы проходим, ждём итоговый 200.
resolve=()
for addr in ${ADDRESSES//,/ }; do resolve+=(--resolve "${addr#*://}:443:127.0.0.1"); done
code() { curl -sS -L --max-redirs 3 -m 10 "${resolve[@]}" -o /dev/null -w '%{http_code}' "https://$1$2" 2>/dev/null || echo 000; }
first=1
for addr in ${ADDRESSES//,/ }; do
    host="${addr#*://}"
    for path in /up /login /manifest.webmanifest; do
        c="$(code "$host" "$path")"
        [ "$c" = 200 ] || problems+=("$host$path → $c")
    done
    if [ $first = 1 ]; then
        c="$(code "$host" /)"; [ "$c" = 200 ] || problems+=("$host/ → $c")
    fi
    first=0
done

for name in app queue queue-long scheduler mail-watch postgres; do
    state="$(docker inspect -f '{{.State.Status}}' "xcar-$name-1" 2>/dev/null || echo missing)"
    [ "$state" = running ] || problems+=("контейнер $name: $state")
done

use="$(df --output=pcent / | tail -1 | tr -dc 0-9)"
[ "${use:-0}" -lt 80 ] || problems+=("корневой диск занят на ${use}%")
if mountpoint -q "$DATA"; then
    use="$(df --output=pcent "$DATA" | tail -1 | tr -dc 0-9)"
    [ "${use:-0}" -lt 80 ] || problems+=("диск данных занят на ${use}%")
else
    problems+=("диск данных $DATA не смонтирован")
fi

# Ночной бэкап ставит отметку после снимка файлов в S3.
last="$(cat "$DATA/backups/last-ok" 2>/dev/null || echo 0)"
[ $(( $(date +%s) - last )) -lt $((26 * 3600)) ] || problems+=("бэкап в S3 старше суток")

failed="$(docker exec xcar-postgres-1 psql -U "$(sed -nE 's/^DB_USERNAME=(.*)$/\1/p' "$ROOT/env/.env.app")" -d "$(sed -nE 's/^DB_DATABASE=(.*)$/\1/p' "$ROOT/env/.env.app")" -tAc 'select count(*) from failed_jobs' 2>/dev/null || echo '?')"
[ "$failed" = 0 ] || [ "$failed" = '?' ] || problems+=("упавших задач: $failed")

report() {
    echo "корень: $(df -h / | awk 'NR==2{print $3 " из " $2}'); docker — $(docker system df --format '{{.Type}} {{.Size}}' 2>/dev/null | paste -sd, - | sed 's/,/, /g')"
    echo "данные: $(df -h "$DATA" | awk 'NR==2{print $3 " из " $2}'); hot: $(du -sh /srv/xcar/hot 2>/dev/null | cut -f1)"
    (cd "$DATA" && du -sh media private postgres cache backups storage 2>/dev/null | awk '{printf "    %-9s %s\n", $2, $1}')
    (cd "$ROOT/current/deploy" && docker compose exec -T app php artisan storage:report 2>/dev/null)
}

# До Telegram с сервера доходит только IPv6.
telegram() {
    local token chat
    token="$(env_value TELEGRAM_BOT_TOKEN)"; chat="$(env_value TELEGRAM_CHAT_ID)"
    [ -n "$token" ] && [ -n "$chat" ] || return 1
    curl -6 -sS -m 10 --retry 2 -o /dev/null "https://api.telegram.org/bot$token/sendMessage" --data-urlencode "chat_id=$chat" --data-urlencode "text=$1"
}
stamp=/run/xcar-check.last

if [ ${#problems[@]} -eq 0 ]; then
    echo "xcar: всё в порядке"
    if [ "${1:-}" = "--telegram" ]; then
        # Беда прошла — сказать об этом один раз.
        [ -s $stamp ] && telegram "xcar: снова всё в порядке" && rm -f $stamp
    else
        report
    fi
    exit 0
fi
msg="xcar: $(printf '%s; ' "${problems[@]}")"
echo "$msg"
if [ "${1:-}" = "--telegram" ]; then
    # Не чаще раза в час на одну и ту же беду.
    last="$(cat $stamp 2>/dev/null || echo)"
    if [ "$last" != "$msg" ] || [ "$(find $stamp -mmin +60 2>/dev/null)" ]; then
        telegram "$msg" && echo "$msg" > $stamp
    fi
fi
exit 1
