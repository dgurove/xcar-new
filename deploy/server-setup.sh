#!/usr/bin/env bash
# Первичная настройка сервера. Запускается один раз с рабочей машины после
# того, как ключ уже на сервере:  ./deploy/server-setup.sh
#
# Что делает: выключает вход по паролю, ставит docker и ufw, размечает
# второй диск под данные (/srv/xcar/data), заводит /srv/xcar/{env,releases},
# кладёт образцы настроек с сгенерированными ключами. Повторный запуск
# безопасен: сделанное не переделывает.
#
#   ./deploy/server-setup.sh          # всё
#   ./deploy/server-setup.sh disk     # только диск данных и настройки docker
#   ./deploy/server-setup.sh tunnel   # только вход для туннеля из Цюриха — Telegram и WhatsApp (zurich/README.md);
#                                     # ключ — ~/.ssh/xcar_tunnel.pub или TUNNEL_KEY_FILE
#   ./deploy/server-setup.sh tunnel-out  # второй путь: туннель, который держит сам прод (zurich/README.md); печатает
#                                        # ключ прода — его в zurich/xcar_out.pub и ./zurich/install.sh на Цюрихе
set -euo pipefail
SSH_HOST="${SSH_HOST:-xcar-next}"
STEP="${1:-all}"
TUNNEL_KEY="$(cat "${TUNNEL_KEY_FILE:-$HOME/.ssh/xcar_tunnel.pub}" 2>/dev/null || true)"
cd "$(dirname "$0")"

ssh "$SSH_HOST" STEP="$STEP" DATA_DEV="${DATA_DEV:-/dev/sdb}" TUNNEL_KEY="$(printf %q "$TUNNEL_KEY")" bash -s <<'REMOTE'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
DATA=/srv/xcar/data

if [ "$STEP" = all ]; then

echo "==> sshd: только ключ"
mkdir -p /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/10-xcar.conf <<'EOC'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOC
sshd -t && systemctl reload ssh 2>/dev/null || systemctl reload sshd

echo "==> часовой пояс, пакеты"
timedatectl set-timezone Europe/Moscow
apt-get update -qq
apt-get install -y -qq ufw curl ca-certificates git unattended-upgrades >/dev/null

echo "==> ufw"
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null
ufw --force enable >/dev/null

if ! command -v docker >/dev/null; then
    echo "==> docker"
    curl -fsSL https://get.docker.com | sh >/dev/null
fi
fi # STEP=all

if [ "$STEP" = all ] || [ "$STEP" = tunnel ]; then
echo "==> туннель в Цюрих: Telegram и WhatsApp"
# Цюрих сам держит обратный SSH-туннель (zurich/README.md). Его пользователю можно одно: слушать на 172.18.0.1 — шлюзе
# сети compose — 9443 (Цюрих ведёт к api.telegram.org:443) и 9444 (служба WhatsApp в Цюрихе). Ни оболочки, ни TTY.
id xcar-tunnel >/dev/null 2>&1 || useradd --system --create-home --shell /usr/sbin/nologin xcar-tunnel
home="$(getent passwd xcar-tunnel | cut -d: -f6)"
if [ -n "$TUNNEL_KEY" ]; then
    install -d -m 700 -o xcar-tunnel -g xcar-tunnel "$home/.ssh"
    echo "restrict,port-forwarding,permitlisten=\"172.18.0.1:9443\",permitlisten=\"172.18.0.1:9444\" $TUNNEL_KEY" > "$home/.ssh/authorized_keys"
    chown xcar-tunnel:xcar-tunnel "$home/.ssh/authorized_keys"
    chmod 600 "$home/.ssh/authorized_keys"
fi
cat > /etc/ssh/sshd_config.d/20-xcar-tunnel.conf <<'EOC'
Match User xcar-tunnel
    GatewayPorts clientspecified
    AllowTcpForwarding remote
    AllowAgentForwarding no
    X11Forwarding no
    PermitTTY no
    # Оборванная без прощания сессия держала бы порт, и новая не встала бы: через 45 с тишины sshd её закрывает.
    ClientAliveInterval 15
    ClientAliveCountMax 3
EOC
sshd -t && { systemctl reload ssh 2>/dev/null || systemctl reload sshd; }
# Конец туннеля видят только контейнеры: снаружи порт закрыт, как всё, кроме 22, 80 и 443.
ufw allow from 172.18.0.0/16 to 172.18.0.1 port 9443 proto tcp >/dev/null
ufw allow from 172.18.0.0/16 to 172.18.0.1 port 9444 proto tcp >/dev/null
fi
[ "$STEP" = tunnel ] && exit 0

if [ "$STEP" = all ] || [ "$STEP" = tunnel-out ]; then
echo "==> второй путь в Цюрих: туннель держит прод"
# Независимо от туннеля Цюриха (03.11.2026: вся связь с Telegram и WhatsApp держалась на одной сессии): прод сам ходит в
# Цюрих и слушает 9445 (→ Telegram 149.154.166.110:443, адресом IPv4 — цель разрешает sshd Цюриха, а IPv6 оттуда моргает)
# и 9446 (→ служба WhatsApp). Ключу на Цюрихе можно только эти два проброса (zurich/install.sh).
ZURICH=188.190.4.62
[ -f /root/.ssh/xcar_out ] || ssh-keygen -q -t ed25519 -N '' -C xcar-out@prod -f /root/.ssh/xcar_out
ssh-keygen -F "$ZURICH" -f /root/.ssh/known_hosts >/dev/null || ssh-keyscan -t ed25519 "$ZURICH" 2>/dev/null >> /root/.ssh/known_hosts
cat > /etc/systemd/system/xcar-tunnel-out.service <<'EOC'
[Unit]
Description=xcar: второй путь к Telegram и WhatsApp — туннель с прода в Цюрих
After=network-online.target docker.service
StartLimitIntervalSec=0

[Service]
ExecStart=/usr/bin/ssh -NT -i /root/.ssh/xcar_out -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -o AddressFamily=inet -L 172.18.0.1:9445:149.154.166.110:443 -L 172.18.0.1:9446:127.0.0.1:9480 claude@188.190.4.62
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOC
systemctl daemon-reload
systemctl enable xcar-tunnel-out >/dev/null 2>&1
ufw allow from 172.18.0.0/16 to 172.18.0.1 port 9445 proto tcp >/dev/null
ufw allow from 172.18.0.0/16 to 172.18.0.1 port 9446 proto tcp >/dev/null
echo "ключ прода для zurich/xcar_out.pub:"; cat /root/.ssh/xcar_out.pub
systemctl restart xcar-tunnel-out
fi
[ "$STEP" = tunnel-out ] && exit 0

echo "==> docker: журналы, зеркало, потолок кэша сборки"
# Зеркало Docker Hub: с нового адреса Hub быстро отвечает 429 на сборке.
# Кэш сборки без потолка съедал по 2,5 ГБ за выкладку — GC демона держит 3 ГБ.
mkdir -p /etc/docker
python3 - <<'EOP'
import json, os
p = '/etc/docker/daemon.json'
cfg = json.load(open(p)) if os.path.exists(p) and os.path.getsize(p) else {}
want = {
    "log-driver": "json-file", "log-opts": {"max-size": "20m", "max-file": "5"},
    "registry-mirrors": ["https://mirror.gcr.io"],
    # Docker 28+: reservedSpace/maxUsedSpace вместо keepStorage.
    "builder": {"gc": {"enabled": True, "policy": [{"maxUsedSpace": "3GB", "all": True}]}},
}
new = {**cfg, **want}
if new != cfg:
    json.dump(new, open(p + '.new', 'w'), indent=2, ensure_ascii=False)
    open('/run/xcar-docker-changed', 'w').close()
EOP
if [ -e /run/xcar-docker-changed ]; then
    rm -f /run/xcar-docker-changed
    # Кривой daemon.json = демон не поднимется вместе с сайтом: сперва проверка.
    if dockerd --validate --config-file /etc/docker/daemon.json.new >/dev/null; then
        mv /etc/docker/daemon.json.new /etc/docker/daemon.json && systemctl restart docker
    else
        rm -f /etc/docker/daemon.json.new
        echo "daemon.json не прошёл проверку — настройки docker не изменены" >&2
        exit 1
    fi
fi

echo "==> диск данных $DATA"
# Второй диск целиком, без таблицы разделов. Размечается только пустой.
if [ -b "$DATA_DEV" ]; then
    blkid "$DATA_DEV" >/dev/null 2>&1 || mkfs.ext4 -q -L xcar-data "$DATA_DEV"
    UUID="$(blkid -s UUID -o value "$DATA_DEV")"
    grep -q "UUID=$UUID" /etc/fstab || echo "UUID=$UUID $DATA ext4 defaults,noatime 0 2" >> /etc/fstab
    mkdir -p "$DATA"
    mountpoint -q "$DATA" || mount "$DATA"
else
    echo "    $DATA_DEV нет — данные останутся на корневом диске в $DATA"
    mkdir -p "$DATA"
fi
# Владельцы — по UID внутри контейнеров: www-data 33, postgres 999.
cd "$DATA"
mkdir -p storage media private cache backups restic-cache
chown 33:33 storage media private cache backups
df -h "$DATA" | awk 'NR==2{print "    " $2 " всего, " $4 " свободно"}'
# Сжатые версии фото — на корневом диске (SSD): пересчитываемое, в бэкап не идёт.
mkdir -p /srv/xcar/hot && chown 33:33 /srv/xcar/hot
# База — тоже на корневом SSD: на HDD второго диска COMMIT стоил секунды (19.10.2026).
mkdir -p /srv/xcar/pg && chown 999:999 /srv/xcar/pg && chmod 750 /srv/xcar/pg

[ "$STEP" = all ] || exit 0

echo "==> каталоги и настройки"
mkdir -p /srv/xcar/env /srv/xcar/releases
cd /srv/xcar/env
gen() { openssl rand -hex "$1"; }
IP=$(hostname -I | awk '{print $1}')
if [ ! -s .env ]; then
    DBP=$(gen 16); PUB=$(gen 32); SUB=$(gen 32)
    cat > .env <<EOE
TAG=
ACME_EMAIL=
SITE_ADDRESSES=http://$IP.nip.io, http://crm.$IP.nip.io, http://park.$IP.nip.io, http://logistics.$IP.nip.io, http://partner.$IP.nip.io
REDIRECT_ADDRESSES=http://$IP
REDIRECT_TO=$IP.nip.io
REDIRECT_SCHEME=http
CADDY_GLOBAL_EXTRA=
DB_DATABASE=xcar
DB_USERNAME=xcar
DB_PASSWORD=$DBP
MERCURE_PUBLISHER_KEY=$PUB
MERCURE_SUBSCRIBER_KEY=$SUB
EOE
fi
if [ ! -s .env.app ]; then
    DBP=$(sed -n 's/^DB_PASSWORD=//p' .env)
    KEY="base64:$(head -c 32 /dev/urandom | base64)"
    ADDR=$(sed -n 's/^SITE_ADDRESSES=//p' .env | cut -d, -f2 | xargs)
    HOST=${ADDR#*://}
    cat > .env.app <<EOE
APP_NAME=XCar
APP_ENV=production
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=$ADDR
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
APP_TIMEZONE=Europe/Moscow
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=xcar
DB_USERNAME=xcar
DB_PASSWORD=$DBP
SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
OCTANE_SERVER=frankenphp
MAIL_MAILER=log
SESSION_DOMAIN=.$HOST
CRM_HOST=crm.$HOST
PARK_HOST=park.$HOST
LOGISTICS_HOST=logistics.$HOST
PARTNER_HOST=partner.$HOST
WEBAUTHN_ID=$HOST
EOE
fi
chmod 600 .env .env.app

echo "==> сеть наружу"
curl -fsS -m 8 -o /dev/null -w 'acme-v02.api.letsencrypt.org: %{http_code}\n' https://acme-v02.api.letsencrypt.org/directory || echo "letsencrypt: недоступен"
echo "готово: $(nproc) ядер, $(free -m | awk '/Mem/{print $2}') МБ, диск $(df -h / | awk 'NR==2{print $4}') свободно"
REMOTE
