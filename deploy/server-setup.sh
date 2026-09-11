#!/usr/bin/env bash
# Первичная настройка сервера. Запускается один раз с рабочей машины после
# того, как ключ уже на сервере:  ./deploy/server-setup.sh
#
# Что делает: выключает вход по паролю, ставит docker и ufw, заводит
# /srv/xcar/{env,releases}, кладёт образцы настроек с сгенерированными ключами.
set -euo pipefail
SSH_HOST="${SSH_HOST:-xcar-next}"
cd "$(dirname "$0")"

ssh "$SSH_HOST" bash -s <<'REMOTE'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

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
mkdir -p /etc/docker
[ -s /etc/docker/daemon.json ] || cat > /etc/docker/daemon.json <<'EOJ'
{ "log-driver": "json-file", "log-opts": { "max-size": "20m", "max-file": "5" } }
EOJ
systemctl restart docker

echo "==> каталоги и настройки"
mkdir -p /srv/xcar/env /srv/xcar/releases
cd /srv/xcar/env
gen() { openssl rand -hex "$1"; }
if [ ! -s .env ]; then
    DBP=$(gen 16); PUB=$(gen 32); SUB=$(gen 32)
    cat > .env <<EOE
TAG=
ACME_EMAIL=
SITE_ADDRESSES=http://$(hostname -I | awk '{print $1}')
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
    ADDR=$(sed -n 's/^SITE_ADDRESSES=//p' .env | cut -d, -f1)
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
EOE
fi
chmod 600 .env .env.app

echo "==> сеть наружу"
curl -fsS -m 8 -o /dev/null -w 'api.telegram.org: %{http_code}\n' https://api.telegram.org || echo "api.telegram.org: недоступен"
curl -fsS -m 8 -o /dev/null -w 'acme-v02.api.letsencrypt.org: %{http_code}\n' https://acme-v02.api.letsencrypt.org/directory || echo "letsencrypt: недоступен"
echo "готово: $(nproc) ядер, $(free -m | awk '/Mem/{print $2}') МБ, диск $(df -h / | awk 'NR==2{print $4}') свободно"
REMOTE
