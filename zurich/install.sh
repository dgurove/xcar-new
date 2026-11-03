#!/usr/bin/env bash
# Ставит на zurich туннель и службу WhatsApp (README.md). Повторный запуск безопасен.
set -euo pipefail
cd "$(dirname "$0")"
PROD=200.169.180.17
[ -f ~/.ssh/xcar_tunnel ] || ssh-keygen -q -t ed25519 -N '' -C xcar-tunnel@zurich -f ~/.ssh/xcar_tunnel
# Служба ходит с BatchMode: без ключа прода в known_hosts она молча падала бы по кругу. Сверьте отпечаток с выводом
# `ssh xcar-next ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.
if ! ssh-keygen -F "$PROD" >/dev/null; then
    ssh-keyscan -t ed25519 "$PROD" 2>/dev/null >> ~/.ssh/known_hosts
    ssh-keygen -lf <(ssh-keygen -F "$PROD" | grep -v '^#' | cut -d' ' -f2-)
fi

# Второй путь — туннель, который держит прод (`deploy/server-setup.sh tunnel-out`): его ключу здесь можно только два
# проброса — до Telegram (IPv4) и до службы WhatsApp. Ни оболочки, ни команд, ни агента.
OUT="restrict,port-forwarding,command=\"/bin/false\",permitopen=\"149.154.166.110:443\",permitopen=\"127.0.0.1:9480\" $(cat xcar_out.pub)"
install -d -m 700 ~/.ssh
touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
grep -qF "$(cut -d' ' -f2 xcar_out.pub)" ~/.ssh/authorized_keys || echo "$OUT" >> ~/.ssh/authorized_keys

# WhatsApp: собрать (Go — ~/.local/go), ключ для прода и пустая группа — один раз.
GO=~/.local/go/bin/go
if [ -x "$GO" ]; then
    mkdir -p ~/.local/bin
    nice -n 10 "$GO" build -C whatsapp -trimpath -ldflags='-s -w' -o ~/.local/bin/xcar-whatsapp .
else
    echo "Go нет в ~/.local/go — xcar-whatsapp не собран" >&2
fi
ENV=~/.config/xcar-whatsapp/env
if [ ! -f "$ENV" ]; then
    install -d -m 700 ~/.config/xcar-whatsapp
    ( umask 077; printf 'TOKEN=%s\nTARGET=\n' "$(openssl rand -hex 24)" > "$ENV" )
fi

mkdir -p ~/.config/systemd/user
cp systemd/*.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable xcar-tunnel xcar-whatsapp
systemctl --user restart xcar-tunnel
systemctl --user --no-pager status xcar-tunnel | head -5
# Не привязанной службе подключаться не к чему: сначала `xcar-whatsapp pair <номер>`.
if [ -f ~/.local/share/xcar-whatsapp/session.db ]; then
    systemctl --user restart xcar-whatsapp
    systemctl --user --no-pager status xcar-whatsapp | head -5
else
    echo "WhatsApp не привязан: ~/.local/bin/xcar-whatsapp pair <номер>, потом systemctl --user start xcar-whatsapp"
fi
