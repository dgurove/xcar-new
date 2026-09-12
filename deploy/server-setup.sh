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
set -euo pipefail
SSH_HOST="${SSH_HOST:-xcar-next}"
STEP="${1:-all}"
cd "$(dirname "$0")"

ssh "$SSH_HOST" STEP="$STEP" DATA_DEV="${DATA_DEV:-/dev/sdb}" bash -s <<'REMOTE'
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
    "builder": {"gc": {"enabled": True, "defaultKeepStorage": "3GB"}},
}
new = {**cfg, **want}
if new != cfg:
    json.dump(new, open(p, 'w'), indent=2, ensure_ascii=False)
    open('/run/xcar-docker-changed', 'w').close()
EOP
if [ -e /run/xcar-docker-changed ]; then rm -f /run/xcar-docker-changed; systemctl restart docker; fi

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
mkdir -p storage media private cache postgres backups
chown 33:33 storage media private cache backups
chown 999:999 postgres
chmod 750 postgres
df -h "$DATA" | awk 'NR==2{print "    " $2 " всего, " $4 " свободно"}'

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
SITE_ADDRESSES=http://$IP, http://$IP.nip.io, http://crm.$IP.nip.io, http://park.$IP.nip.io
REDIRECT_ADDRESSES=http://redirect.localhost
REDIRECT_TO=xcar.ru
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
WEBAUTHN_ID=$HOST
WEBAUTHN_ORIGINS=http://$HOST,http://crm.$HOST,http://park.$HOST
EOE
fi
chmod 600 .env .env.app

echo "==> сеть наружу"
curl -fsS -m 8 -o /dev/null -w 'api.telegram.org: %{http_code}\n' https://api.telegram.org || echo "api.telegram.org: недоступен"
curl -fsS -m 8 -o /dev/null -w 'acme-v02.api.letsencrypt.org: %{http_code}\n' https://acme-v02.api.letsencrypt.org/directory || echo "letsencrypt: недоступен"
echo "готово: $(nproc) ядер, $(free -m | awk '/Mem/{print $2}') МБ, диск $(df -h / | awk 'NR==2{print $4}') свободно"
REMOTE
