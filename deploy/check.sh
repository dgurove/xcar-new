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
# Один из двух путей лёг, второй работает (zurich/README.md) — не беда, а заметка: владельцу не чаще раза в 6 часов.
degraded=()

# Каждое имя: сайт отдаёт страницы, CRM и стоянка гостю — вход. По https на себя: на :80 Caddy отвечает 308,
# а прежние хосты (garage) — 301 на сайт; редиректы проходим, ждём итоговый 200.
resolve=()
for addr in ${ADDRESSES//,/ }; do resolve+=(--resolve "${addr#*://}:443:127.0.0.1"); done
code() { curl -sS -L --max-redirs 3 -m 10 "${resolve[@]}" -o /dev/null -w '%{http_code}' "https://$1$2" 2>/dev/null || echo 000; }
# Страницы и контейнеры — дважды: выкладка пересоздаёт контейнеры секунд двадцать, а под нагрузкой одна страница может
# не уложиться в 10 с. Тревога — только если и через 45 с не так (16.10.2026: «всё 000000» прилетало посреди выкладки).
probe() {
    problems=()
    local first=1 host c state
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
    for name in app queue queue-scan queue-long queue-mail queue-files scheduler mail-watch offers-bot postgres; do
        state="$(docker inspect -f '{{.State.Status}}' "xcar-$name-1" 2>/dev/null || echo missing)"
        [ "$state" = running ] || problems+=("контейнер $name: $state")
    done
    # Пути к Telegram (zurich/README.md): туннель Цюриха (9443) и туннель прода (9445); приложение переключается само
    # (`Support\Routes`). Проверка — из контейнера, до самого Telegram: открытый порт ещё ничего не значит — его держит sshd,
    # даже когда дальше не дойти. Беда — только когда не отвечает ни один путь.
    degraded=()
    local tg_ok=0 route
    for route in zurich:9443 zurich:9445; do
        c="$(docker exec xcar-app-1 curl -sS -m 8 -o /dev/null -w '%{http_code}' --connect-to "api.telegram.org:443:$route" https://api.telegram.org/ 2>/dev/null)"
        if [ -n "$c" ] && [ "$c" != 000 ]; then tg_ok=1; else degraded+=("путь $route до Telegram не отвечает"); fi
    done
    [ $tg_ok = 1 ] || problems+=("Telegram недоступен: не отвечают оба туннеля (zurich:9443 и zurich:9445)")
    # WhatsApp — служба в Цюрихе теми же туннелями (9444, 9446): на связи, вошла, в группе. Без WHATSAPP_VIA молчит.
    for route in zurich:9444 zurich:9446; do
        c="$(docker exec xcar-app-1 curl -sS -m 8 -o /dev/null -w '%{http_code}' "http://$route/status" 2>/dev/null)"
        [ -n "$c" ] && [ "$c" != 000 ] || degraded+=("путь $route до WhatsApp не отвечает")
    done
    local w
    w="$(docker exec xcar-app-1 php artisan whatsapp:status 2>&1)" || problems+=("WhatsApp: ${w:-не отвечает}")
}
probe
if [ ${#problems[@]} -gt 0 ]; then sleep 45; probe; fi

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

sql() { docker exec xcar-postgres-1 psql -U "$(sed -nE 's/^DB_USERNAME=(.*)$/\1/p' "$ROOT/env/.env.app")" -d "$(sed -nE 's/^DB_DATABASE=(.*)$/\1/p' "$ROOT/env/.env.app")" -tAc "$1" 2>/dev/null; }
# Упавшая задача — событие, а не состояние сайта: о каждой новой — одно сообщение с её именем, без «снова всё в
# порядке» следом. Прежде считалось «упавших задач: N», и одна упавшая напоминала о себе каждый час неделю, пока
# её не убирала уборка (storage:gc, 7 дней). Последний виденный номер — на диске: /run чистится перезагрузкой.
failed_seen=/var/lib/xcar-check.failed-id
failed_max="$(sql 'select coalesce(max(id), 0) from failed_jobs')"
failed_new=''
if [ -n "$failed_max" ]; then
    failed_prev="$(cat $failed_seen 2>/dev/null || echo "$failed_max")"
    if [ "$failed_max" -gt "$failed_prev" ] 2>/dev/null; then
        failed_new="$(sql "select string_agg(distinct reverse(split_part(reverse(payload::json->>'displayName'), chr(92), 1)), ', ') from failed_jobs where id > $failed_prev")"
    fi
fi

report() {
    echo "корень: $(df -h / | awk 'NR==2{print $3 " из " $2}'); docker — $(docker system df --format '{{.Type}} {{.Size}}' 2>/dev/null | paste -sd, - | sed 's/,/, /g')"
    echo "данные: $(df -h "$DATA" | awk 'NR==2{print $3 " из " $2}'); hot: $(du -sh /srv/xcar/hot 2>/dev/null | cut -f1); база: $(du -sh /srv/xcar/pg 2>/dev/null | cut -f1)"
    (cd "$DATA" && du -sh media private cache backups storage 2>/dev/null | awk '{printf "    %-9s %s\n", $2, $1}')
    (cd "$ROOT/current/deploy" && docker compose exec -T app php artisan storage:report 2>/dev/null)
}

# Тревоги — только главному администратору (TELEGRAM_CHAT_ID — чат Дмитрия; 03.11.2026, владелец: «все ошибки только мне»).
# К Telegram — первым живым путём: туннель Цюриха, туннель прода, напрямую по IPv6 и IPv4 (блокировка неполная) — лёгший
# путь не мешает сказать о себе.
telegram() {
    local token chat args via
    token="$(env_value TELEGRAM_BOT_TOKEN)"; chat="$(env_value TELEGRAM_CHAT_ID)"
    [ -n "$token" ] && [ -n "$chat" ] || return 1
    args=(-sS -m 10 -o /dev/null "https://api.telegram.org/bot$token/sendMessage" --data-urlencode "chat_id=$chat" --data-urlencode "text=$1")
    for via in 172.18.0.1:9443 172.18.0.1:9445; do curl --connect-to "api.telegram.org:443:$via" "${args[@]}" 2>/dev/null && return 0; done
    curl -6 "${args[@]}" 2>/dev/null || curl -4 "${args[@]}"
}
stamp=/run/xcar-check.last

if [ -n "$failed_new" ]; then
    echo "xcar: упала задача $failed_new"
    if [ "${1:-}" = "--telegram" ]; then
        telegram "xcar: упала задача $failed_new (php artisan queue:failed)" && echo "$failed_max" > $failed_seen
    fi
elif [ -n "$failed_max" ] && [ "${1:-}" = "--telegram" ]; then
    echo "$failed_max" > $failed_seen
fi

# Один путь лёг, второй работает — заметка не чаще раза в 6 часов (на одну и ту же), без «снова в порядке».
if [ ${#degraded[@]} -gt 0 ]; then
    note="xcar: $(printf '%s; ' "${degraded[@]}")работает запасной"
    echo "$note"
    notestamp=/run/xcar-check.degraded
    if [ "${1:-}" = "--telegram" ] && [ ${#problems[@]} -eq 0 ] && { [ "$(cat $notestamp 2>/dev/null)" != "$note" ] || [ -n "$(find $notestamp -mmin +360 2>/dev/null)" ]; }; then
        telegram "$note" && echo "$note" > $notestamp
    fi
fi

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
