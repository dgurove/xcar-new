#!/usr/bin/env bash
# Ставит на zurich службы из systemd/ (README.md). Повторный запуск безопасен.
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
mkdir -p ~/.config/systemd/user
cp systemd/*.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable xcar-tunnel
systemctl --user restart xcar-tunnel
systemctl --user --no-pager status xcar-tunnel | head -5
