#!/usr/bin/env bash
# Локальный сервер без докера: php@8.5 и postgres из brew.
#   ./serve-local.sh            # http://localhost:8010
#   ./serve-local.sh --host=0.0.0.0   # чтобы открыть с телефона по Wi-Fi
set -euo pipefail
cd "$(dirname "$0")"
export PATH="/opt/homebrew/opt/php@8.5/bin:$PATH"
pgrep -fq 'postgres.*postgresql@18' || brew services start postgresql@18
exec php artisan serve --port=8010 "$@"
