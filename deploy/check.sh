#!/usr/bin/env bash
# Проверка живости: страницы, контейнеры, диск, очередь. С --telegram шлёт
# сообщение при сбое (TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в /srv/xcar/env/.env).
set -uo pipefail
ROOT=/srv/xcar
env_value() { sed -nE "s/^$1=\"?([^\"]*)\"?\r?$/\1/p" "$ROOT/env/.env" | tail -1; }
SITE="$(env_value SITE_ADDRESSES | cut -d, -f1 | xargs)"; SITE="${SITE:-http://127.0.0.1}"
case "$SITE" in http*) ;; *) SITE="https://$SITE" ;; esac
problems=()

code() { curl -sS -m 10 -o /dev/null -w '%{http_code}' -H 'Host: '"${SITE#*://}" "$@" 2>/dev/null || echo 000; }
for path in /up / /vhod /manifest.webmanifest; do
    c="$(code "http://127.0.0.1$path")"
    [ "$c" = 200 ] || problems+=("$path → $c")
done

for name in app queue queue-long scheduler mail-watch postgres; do
    state="$(docker inspect -f '{{.State.Status}}' "xcar-$name-1" 2>/dev/null || echo missing)"
    [ "$state" = running ] || problems+=("контейнер $name: $state")
done

use="$(df --output=pcent / | tail -1 | tr -dc 0-9)"
[ "${use:-0}" -lt 85 ] || problems+=("диск занят на ${use}%")

failed="$(docker exec xcar-postgres-1 psql -U "$(sed -nE 's/^DB_USERNAME=(.*)$/\1/p' "$ROOT/env/.env.app")" -d "$(sed -nE 's/^DB_DATABASE=(.*)$/\1/p' "$ROOT/env/.env.app")" -tAc 'select count(*) from failed_jobs' 2>/dev/null || echo '?')"
[ "$failed" = 0 ] || [ "$failed" = '?' ] || problems+=("упавших задач: $failed")

if [ ${#problems[@]} -eq 0 ]; then
    echo "xcar: всё в порядке"; exit 0
fi
msg="xcar: $(printf '%s; ' "${problems[@]}")"
echo "$msg"
if [ "${1:-}" = "--telegram" ]; then
    token="$(env_value TELEGRAM_BOT_TOKEN)"; chat="$(env_value TELEGRAM_CHAT_ID)"
    if [ -n "$token" ] && [ -n "$chat" ]; then
        # Не чаще раза в час на одну и ту же беду.
        stamp=/run/xcar-check.last; last="$(cat $stamp 2>/dev/null || echo)"
        if [ "$last" != "$msg" ] || [ "$(find $stamp -mmin +60 2>/dev/null)" ]; then
            curl -sS -m 10 -o /dev/null "https://api.telegram.org/bot$token/sendMessage" --data-urlencode "chat_id=$chat" --data-urlencode "text=$msg" && echo "$msg" > $stamp
        fi
    fi
fi
exit 1
