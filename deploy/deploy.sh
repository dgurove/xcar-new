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

# Выкладки — по одной (18.11.2026: две сессии выкладывали разом — под новым тегом на проде оказался код без последнего
# коммита, следующие две падали на «образ на сервер»). Общее у выкладок: тег в `$ROOT/env/.env`, реестр образов с его
# уборкой, контейнеры прода. Замок один на все рабочие копии (`~/.cache/xcar-build`), вторая выкладка ждёт первую.
LOCK="${XDG_CACHE_HOME:-$HOME/.cache}/xcar-build/deploy.lock"
alone() {
    command -v flock >/dev/null || return 0
    mkdir -p "$(dirname "$LOCK")"
    exec 9>>"$LOCK"
    if ! flock -n 9; then
        echo "==> идёт другая выкладка ($(cat "$LOCK.who" 2>/dev/null || echo '?')), ждём"
        flock 9
    fi
    echo "$TAG из $(pwd), $(date +%H:%M:%S)" > "$LOCK.who"
}

# Пока ждали (или не подтянули main), на прод мог уйти чужой коммит: выкладка без него молча откатила бы чужую работу.
# Коммит прода — `RELEASE` выпуска; его нет в HEAD — стоп. `FORCE=1` — выложить всё равно (намеренный откат кодом).
fresh() {
    local live
    live=$(remote "cat $ROOT/current/RELEASE 2>/dev/null" || true)
    [ -n "$live" ] || return 0
    git cat-file -e "$live^{commit}" 2>/dev/null || git fetch -q origin 2>/dev/null || true
    if git merge-base --is-ancestor "$live" HEAD 2>/dev/null; then return 0; fi
    if [ "${FORCE:-}" = 1 ]; then echo "    на проде ${live:0:8}, в HEAD его нет — выкладываем по FORCE=1"; return 0; fi
    echo "стоп: на проде коммит ${live:0:8}, в твоём HEAD его нет — выкладка откатила бы чужую работу." >&2
    echo "      git pull --rebase и снова; намеренно — FORCE=1 ./deploy/deploy.sh" >&2
    exit 1
}

release_dir() { echo "$ROOT/releases/$TAG"; }

# Шаблоны собираются (11.10.2026: «</x-slot:badge>@endif» вплотную ронял карточку сделки, view:cache это пропускает).
# Проверяется рабочее дерево на маке — php@8.5 из brew; нет php — пропуск со словом, не падение.
lint() {
    local php=/opt/homebrew/opt/php@8.5/bin/php
    [ -x "$php" ] || php=$(command -v php || true)
    if [ -z "$php" ]; then echo "    шаблоны не проверены: нет php"; return; fi
    echo "==> шаблоны"
    "$php" scripts/blade-lint.php
    # Сборка поставщика без внутреннего (Partner XCAR, 07.11.2026): ни «Мигторга», ни разбора документов в его браузере.
    if command -v node >/dev/null; then echo "==> сборка партнёра"; node scripts/partner-lint.mjs; else echo "    сборка партнёра не проверена: нет node"; fi
}

snapshot() {
    echo "==> снимок $TAG"
    remote "mkdir -p $(release_dir)"
    COPYFILE_DISABLE=1 git archive --format=tar HEAD | ssh "$SSH_HOST" "tar -x -C $(release_dir)"
    # Какой коммит в выпуске: по нему `fresh` и `verify` (в образ попадает тем же файлом — `build`).
    git rev-parse HEAD | ssh "$SSH_HOST" "cat > $(release_dir)/RELEASE"
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
    git rev-parse HEAD > "$dir/RELEASE"
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
    # Доехал тот же образ, что собран: номер образа — отпечаток его содержимого, у собранного и скачанного он один.
    local built shipped
    built=$(docker image inspect "xcar/app:$TAG" --format '{{.Id}}')
    shipped=$(remote "docker image inspect xcar/app:$TAG --format '{{.Id}}'")
    [ "$built" = "$shipped" ] || { echo "стоп: на сервер доехал не тот образ ($shipped вместо $built)" >&2; exit 1; }
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
    # После уборки реестр перезапускается: он помнит в памяти, какие слои у него есть, и про стёртый уборкой слой
    # отвечал бы следующей выкладке «уже загружен» — образ уходил битым (`short read: expected N bytes but got 0`,
    # 18.11.2026). Уборка и перезапуск — под замком выкладки (`alone`): чужая загрузка в этот момент не идёт.
    if [ -n "$old" ]; then
        docker exec xcar-registry registry garbage-collect --delete-untagged /etc/docker/registry/config.yml >/dev/null 2>&1 || true
        docker restart xcar-registry >/dev/null 2>&1 || true
    fi
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
    # Открытым окнам — «обновляемся» (`release:announce` → заставка `lib/updating.js`): сайт сейчас секунд на двадцать
    # пропадёт, и без слова это выглядит как «сервер упал». Прежний выпуск команды не знает — молча дальше.
    remote "docker exec xcar-app-1 php artisan release:announce >/dev/null 2>&1 && sleep 2 || true"
    # Сначала сайт — и ждём, пока ответит (healthcheck `/up`); работники — следом по одному: разом девять контейнеров
    # на двух ядрах прода давали пик, и страницы не открывались (10.11.2026).
    compose_in "$(release_dir)" up -d --no-deps --wait --wait-timeout 120 app
    remote "cd $(release_dir)/deploy && for s in scheduler queue queue-mail queue-files queue-scan queue-long offers-bot mail-watch; do \
        docker compose up -d --no-deps \$s >/dev/null 2>&1; sleep 3; done"
    compose_in "$(release_dir)" up -d --remove-orphans
    # Старый планировщик убит посреди задач, а их замки withoutOverlapping живут в кэше до своего срока (10–120 минут,
    # `routes/console.php`): часы и почта молчали бы после каждой выкладки. Новые контейнеры ещё ничего не держат.
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

# На проде работает именно этот коммит (18.11.2026: выкладка отчиталась успехом, прод отвечал 200, а код был прежний).
verify() {
    local want got
    want=$(git rev-parse HEAD)
    got=$(remote "docker exec xcar-app-1 cat /app/RELEASE 2>/dev/null" || true)
    [ "$want" = "$got" ] && { echo "    код на проде: ${want:0:8}"; return; }
    echo "стоп: на проде работает ${got:0:8}, а выкладывали ${want:0:8}" >&2
    exit 1
}

check() {
    echo "==> проверка"
    remote "curl -fsS -o /dev/null -w 'up: %{http_code}\n' http://127.0.0.1/up"
    compose_in "$(current_dir)" ps
}

case "${1:-all}" in
    all)      alone; fresh; lint; snapshot; build; migrate; up; check; verify ;;
    snapshot) alone; snapshot ;;
    build)    alone; lint; snapshot; build ;;
    migrate)  alone; compose_in "$(current_dir)" up -d --wait postgres; compose_in "$(current_dir)" run --rm --no-deps app php artisan migrate --force ;;
    up)       alone; compose_in "$(current_dir)" up -d --remove-orphans ;;
    ps)       compose_in "$(current_dir)" ps ;;
    logs)     shift; compose_in "$(current_dir)" logs --tail=200 "$@" ;;
    artisan)  shift; compose_in "$(current_dir)" exec app php artisan "$@" ;;
    check)    check ;;
    backup)   remote "bash $ROOT/current/deploy/backup.sh ${2:-all}" ;;
    rollback)
        TAG="$2"; alone; [ -d "$(release_dir)" ] || true
        remote "sed -i 's/^TAG=.*/TAG=$TAG/' $ROOT/env/.env"
        compose_in "$(release_dir)" up -d --remove-orphans
        remote "ln -sfn $(release_dir) $ROOT/current"; check ;;
    *) echo "неизвестная команда: $1" >&2; exit 2 ;;
esac
