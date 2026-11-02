#!/usr/bin/env bash
# Ставит на zurich службы из systemd/ (README.md). Повторный запуск безопасен.
set -euo pipefail
cd "$(dirname "$0")"
[ -f ~/.ssh/xcar_tunnel ] || ssh-keygen -q -t ed25519 -N '' -C xcar-tunnel@zurich -f ~/.ssh/xcar_tunnel
mkdir -p ~/.config/systemd/user
cp systemd/*.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now xcar-tunnel
systemctl --user restart xcar-tunnel
systemctl --user --no-pager status xcar-tunnel | head -5
