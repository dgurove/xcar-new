#!/usr/bin/env bash
# Выкладка xcar-new. Запускается с рабочей машины:
#
#   ./deploy/deploy.sh            # всё: снимок → сборка → миграции → up
#   ./deploy/deploy.sh build|migrate|up|ps|logs|artisan …|rollback TAG
#
# Снимок — `git archive HEAD`, а не рабочее дерево: в дереве идёт
# незакоммиченная работа. Образ собирается здесь, на zurich, а не на проде (10.11.2026: сборка забирала оба ядра
# прода на минуты, 6–7 выкладок в час — «не грузит система»); на прод уходят только новые слои через свой реестр
# (`ship`). `BUILD_ON=prod` — по-старому, на сервере.
set -euo pipefail

SSH_HOST="${SSH_HOST:-xcar-next}"
ROOT=/srv/xcar
TAG="${TAG:-dg-$(date +%Y%m%d-%H%M%S)}"
BUILD_ON="${BUILD_ON:-here}"
# Реестр образов на zurich (только 127.0.0.1) и его порт на проде — обратным туннелем ssh на время `docker pull`.
REGISTRY=127.0.0.1:5000
REMOTE_REGISTRY=127.0.0.1:5055
cd "$(dirname "$0")/.."

remote() { ssh "$SSH_HOST" "$@"; }

release_dir() { echo "$ROOT/releases/$TAG"; }

# Шаблоны собираются (11.10.2026: «</x-slot:badge>@endif» вплотную ронял карточку сделки, view:cache это пропускает).
# Проверяется рабочее дерево на маке — php@8.5 из brew; нет php — пропуск со словом, не падение.
lint() {
    local php=/opt/homebrew/opt/php@8.5/bin/php
    [ -x "$php" ] || php=$(command -v php || true)
    if [ -z "$php" ]; then echo "    шаблоны не проверены: нет php"; return; fi
    echo "==> шаблоны"
    "$php" scripts/blade-lint.php
    # Сборка поставщика без внутреннего (Partner XCar, 07.11.2026): ни «Мигторга», ни разбора документов в его браузере.
    if command -v node >/dev/null; then echo "==> сборка партнёра"; node scripts/partner-lint.mjs; else echo "    сборка партнёра не проверена: нет node"; fi
}

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
    if [ "$BUILD_ON" = prod ]; then
        echo "==> сборка образа на сервере"
        compose_in "$(release_dir)" build app
        # Кэш, которого эта сборка не коснулась двое суток, — после сборки, не до:
        # снимок контекста выкладки нужен ей самой (обжигались 08.09). --max-used-space в Docker 29 ничего не снимает.
        # Кэш колёс pip (`id=xcar-pip`) не трогаем: без него слой OCR качается с PyPI полчаса.
        remote "docker builder prune -f --filter until=48h --filter type!=exec.cachemount >/dev/null 2>&1 || true"
        return
    fi
    echo "==> сборка образа здесь"
    local dir="${XDG_CACHE_HOME:-$HOME/.cache}/xcar-build/$TAG"
    rm -rf "$dir" && mkdir -p "$dir"
    git archive --format=tar HEAD | tar -x -C "$dir"
    nice -n 10 docker build -q -f "$dir/deploy/Dockerfile" --target app -t "xcar/app:$TAG" "$dir" >/dev/null
    rm -rf "$dir"
    docker builder prune -f --filter until=48h --filter type!=exec.cachemount >/dev/null 2>&1 || true
    ship
}

registry() { # свой реестр образов на zurich, удаление манифестов включено — старые чистит `ship`
    docker ps -q --filter name='^xcar-registry$' | grep -q . && return
    docker rm -f xcar-registry >/dev/null 2>&1 || true
    docker run -d --restart unless-stopped --name xcar-registry -p "$REGISTRY:5000" \
        -e REGISTRY_STORAGE_DELETE_ENABLED=true -v xcar-registry:/var/lib/registry registry:2 >/dev/null
    sleep 2
}

ship() { # готовый образ на прод: в реестр здесь, оттуда прод тянет только новые слои
    echo "==> образ на сервер"
    registry
    docker tag "xcar/app:$TAG" "$REGISTRY/xcar/app:$TAG"
    docker push -q "$REGISTRY/xcar/app:$TAG" >/dev/null
    docker rmi "$REGISTRY/xcar/app:$TAG" >/dev/null
    ssh -o ExitOnForwardFailure=yes -R "${REMOTE_REGISTRY#*:}:$REGISTRY" "$SSH_HOST" \
        "docker pull -q $REMOTE_REGISTRY/xcar/app:$TAG >/dev/null && docker tag $REMOTE_REGISTRY/xcar/app:$TAG xcar/app:$TAG && docker rmi $REMOTE_REGISTRY/xcar/app:$TAG >/dev/null"
    # Здесь и в реестре — три последних: прод держит свои три сам (`prune`).
    local old
    old=$(docker images xcar/app --format '{{.Tag}}' | grep '^dg-' | sort -r | tail -n +4 || true)
    for t in $old; do
        docker rmi "xcar/app:$t" >/dev/null 2>&1 || true
        local digest
        digest=$(curl -sfI -H 'Accept: application/vnd.docker.distribution.manifest.v2+json' -H 'Accept: application/vnd.oci.image.manifest.v1+json' \
            "http://$REGISTRY/v2/xcar/app/manifests/$t" | tr -d '\r' | awk -F': ' 'tolower($1)=="docker-content-digest"{print $2}' || true)
        # Тега в реестре уже нет (404, curl -f — код 22) — при pipefail это обрывало выкладку после того, как образ уже на проде.
        [ -n "$digest" ] && curl -sf -X DELETE "http://$REGISTRY/v2/xcar/app/manifests/$digest" >/dev/null || true
    done
    [ -n "$old" ] && docker exec xcar-registry registry garbage-collect --delete-untagged /etc/docker/registry/config.yml >/dev/null 2>&1 || true
}

migrate() {
    echo "==> миграции"
    compose_in "$(release_dir)" up -d --wait postgres
    # Дамп базы перед миграциями: откатить их нечем. Только когда они есть (`--pending=1` отвечает 1; ошибка — тоже
    # дамп): полный дамп на каждую выкладку — минута чтения с диска, а выкладок бывает по 6–7 в час (19.10.2026).
    # Первый раз базы может не быть.
    if compose_in "$(release_dir)" run --rm --no-deps app php artisan migrate:status --pending=1 >/dev/null 2>&1; then
        echo "    миграций нет, дамп не нужен"
    else
        remote "docker exec xcar-postgres-1 pg_isready -q 2>/dev/null && bash $(release_dir)/deploy/backup.sh db || echo '    бэкап пропущен'"
    fi
    compose_in "$(release_dir)" run --rm --no-deps app php artisan migrate --force
}

up() {
    echo "==> запуск"
    # Сначала сайт — и ждём, пока ответит (healthcheck `/up`); работники — следом по одному: разом девять контейнеров
    # на двух ядрах прода давали пик, и страницы не открывались (10.11.2026).
    compose_in "$(release_dir)" up -d --no-deps --wait --wait-timeout 120 app
    remote "cd $(release_dir)/deploy && for s in scheduler queue queue-mail queue-files queue-scan queue-long offers-bot mail-watch; do \
        docker compose up -d --no-deps \$s >/dev/null 2>&1; sleep 3; done"
    compose_in "$(release_dir)" up -d --remove-orphans
    # Старый планировщик убит посреди задач, а их замки withoutOverlapping живут в кэше до 10 минут:
    # бот (telegram:poll) и почта молчали бы после каждой выкладки. Новые контейнеры ещё ничего не держат.
    compose_in "$(release_dir)" exec -T scheduler php artisan schedule:clear-cache || true
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
    all)      lint; snapshot; build; migrate; up; check ;;
    snapshot) snapshot ;;
    build)    lint; snapshot; build ;;
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
