#!/usr/bin/env bash
# Выкладка xcar-new. Запускается с рабочей машины:
#
#   ./deploy/deploy.sh            # всё: снимок → сборка → миграции → up
#   ./deploy/deploy.sh build|migrate|up|ps|logs|artisan …|rollback TAG
#
# Снимок — `git archive HEAD`, а не рабочее дерево: в дереве идёт
# незакоммиченная работа. Образ собирается на сервере (докера на маке нет).
set -euo pipefail

SSH_HOST="${SSH_HOST:-xcar-next}"
ROOT=/srv/xcar
TAG="${TAG:-dg-$(date +%Y%m%d-%H%M%S)}"
cd "$(dirname "$0")/.."

remote() { ssh "$SSH_HOST" "$@"; }

release_dir() { echo "$ROOT/releases/$TAG"; }

snapshot() {
    echo "==> снимок $TAG"
    remote "mkdir -p $(release_dir)"
    COPYFILE_DISABLE=1 git archive --format=tar HEAD | ssh "$SSH_HOST" "tar -x -C $(release_dir)"
    remote "cd $(release_dir)/deploy \
        && ln -sf $ROOT/env/.env .env \
        && ln -sf $ROOT/env/.env.app .env.app \
        && sed -i 's/^TAG=.*/TAG=$TAG/' $ROOT/env/.env"
}

compose_in() { # каталог release, аргументы compose
    local dir=$1; shift
    remote "cd $dir/deploy && docker compose $*"
}

current_dir() { remote "readlink -f $ROOT/current"; }

build() {
    echo "==> сборка образа"
    # Кэш сборки подрезает сам демон (builder.gc в daemon.json, server-setup.sh).
    compose_in "$(release_dir)" build app
}

migrate() {
    echo "==> миграции"
    compose_in "$(release_dir)" up -d --wait postgres
    # Дамп базы перед миграциями: откатить их нечем. Первый раз базы может не быть.
    remote "docker exec xcar-postgres-1 pg_isready -q 2>/dev/null && bash $(release_dir)/deploy/backup.sh db || echo '    бэкап пропущен'"
    compose_in "$(release_dir)" run --rm --no-deps app php artisan migrate --force
}

up() {
    echo "==> запуск"
    compose_in "$(release_dir)" up -d --remove-orphans
    remote "ln -sfn $(release_dir) $ROOT/current"
    timers
    prune
}

timers() {
    remote "for u in xcar-backup.service xcar-backup.timer xcar-check.service xcar-check.timer; do ln -sfn $ROOT/current/deploy/systemd/\$u /etc/systemd/system/\$u; done; \
        systemctl daemon-reload && systemctl enable --now xcar-backup.timer xcar-check.timer >/dev/null 2>&1 || true"
}

prune() { # три последних выпуска и образа: есть куда откатиться, корневой диск не растёт
    remote "cd $ROOT/releases && ls -1dt dg-* 2>/dev/null | tail -n +4 | xargs -r rm -rf; \
        docker images 'xcar/app' --format '{{.Tag}}' | sort -r | tail -n +4 | xargs -r -I{} docker rmi xcar/app:{} >/dev/null 2>&1 || true"
}

check() {
    echo "==> проверка"
    remote "curl -fsS -o /dev/null -w 'up: %{http_code}\n' http://127.0.0.1/up"
    compose_in "$(current_dir)" ps
}

case "${1:-all}" in
    all)      snapshot; build; migrate; up; check ;;
    snapshot) snapshot ;;
    build)    snapshot; build ;;
    migrate)  compose_in "$(current_dir)" up -d --wait postgres; compose_in "$(current_dir)" run --rm --no-deps app php artisan migrate --force ;;
    up)       compose_in "$(current_dir)" up -d --remove-orphans ;;
    ps)       compose_in "$(current_dir)" ps ;;
    logs)     shift; compose_in "$(current_dir)" logs --tail=200 "$@" ;;
    artisan)  shift; compose_in "$(current_dir)" exec app php artisan "$@" ;;
    check)    check ;;
    backup)   remote "bash $ROOT/current/deploy/backup.sh ${2:-all}" ;;
    rollback)
        TAG="$2"; [ -d "$(release_dir)" ] || true
        remote "sed -i 's/^TAG=.*/TAG=$TAG/' $ROOT/env/.env"
        compose_in "$(release_dir)" up -d --remove-orphans
        remote "ln -sfn $(release_dir) $ROOT/current"; check ;;
    *) echo "неизвестная команда: $1" >&2; exit 2 ;;
esac
