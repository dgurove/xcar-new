# Образ xcar-new. Один на всё: сервер, очередь, планировщик, наблюдатели почты —
# различаются только командой.
#
# app — FrankenPHP в режиме воркера (Laravel Octane): TLS, HTTP/3, статика,
# PHP и хаб Mercure одним процессом. Node живёт только на стадии сборки.

ARG FRANKENPHP_VERSION=1.12.7
ARG PHP_VERSION=8.5
ARG NODE_VERSION=24
ARG COMPOSER_VERSION=2.9.5

# --------------------------------------------------------------- база
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS base

WORKDIR /app

RUN install-php-extensions bcmath exif gd intl pcntl pdo_pgsql zip
# p7zip — архивы фотографий от страховых приходят и в .7z. heic2jpg — HEIC с айфонов
# из облака Carcade: libheif из Debian 12 их не читает, декодер — pillow-heif со своим libheif.
# poppler-utils — pdftotext: текст оценки с торгов и акта во вложении письма (`AttachmentText`).
RUN apt-get update && apt-get install -y --no-install-recommends p7zip-full poppler-utils python3 python3-pip && rm -rf /var/lib/apt/lists/* \
    && pip3 install --no-cache-dir --break-system-packages pillow==12.3.0 pillow-heif==1.7.0
COPY deploy/bin/heic2jpg /usr/local/bin/heic2jpg
# ocr — «✨ Распознать» в «Из писем» (`DocumentText`): PaddleOCR PP-OCRv5 через RapidOCR на onnxruntime и модель
# поворота страницы. rapidocr тянет полный opencv с libGL — ставим без зависимостей, opencv без экрана. Модели
# качаются при первом вызове: прогоняем его на сборке, в работе сеть не нужна. Отдельным слоем от pillow.
RUN pip3 install --no-cache-dir --break-system-packages onnxruntime==1.30.0 opencv-python-headless==5.0.0.93 numpy==2.4.6 \
        pyclipper==1.4.0 shapely==2.1.2 omegaconf==2.3.1 colorlog==6.12.0 PyYAML==6.0.3 requests==2.34.2 six==1.17.0 tqdm==4.70.1 \
    && pip3 install --no-cache-dir --break-system-packages --no-deps rapidocr==3.9.2 rapid-orientation==0.0.11
COPY deploy/bin/ocr /usr/local/bin/ocr
RUN python3 -c "from PIL import Image; Image.new('RGB', (320, 80), 'white').save('/tmp/page-warm.png')" \
    && ocr /tmp/page-warm.png && rm /tmp/page-warm.png

COPY deploy/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Конфиг сервера проверяем на сборке: опечатка не должна всплывать при старте.
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
RUN APP_PUBLIC_PATH=/app/public CADDY_SERVER_ADMIN_HOST=127.0.0.1 CADDY_SERVER_ADMIN_PORT=2019 \
    MERCURE_PUBLISHER_KEY=proverka MERCURE_SUBSCRIBER_KEY=proverka \
    frankenphp validate --config /etc/frankenphp/Caddyfile

# ------------------------------------------------------------- vendor
FROM composer:${COMPOSER_VERSION} AS composer-bin

FROM base AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ------------------------------------------------------------- ассеты
FROM node:${NODE_VERSION}-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY scripts/precompress.mjs ./scripts/
RUN npm run build && test -s public/build/manifest.json && node scripts/precompress.mjs

# ------------------------------------------------------------ runtime
FROM base AS app

COPY --chown=www-data:www-data . ./
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

# Скомпилированные блейды живут в образе, а не в томе storage: том переживает
# выкладки, а Blade сравнивает mtime исходника (время коммита из git archive)
# с mtime компилята — коммит, сделанный во время предыдущей выкладки, оставлял
# в силе старый шаблон (так всплывал прежний таб-бар с иконками). Каждый релиз
# компилирует все виды при сборке в свой каталог.
ENV VIEW_COMPILED_PATH=/app/bootstrap/cache/views

# Каталоги storage — до dump-autoload: package:discover поднимает приложение,
# и без storage/framework/views оно падает на компиляторе блейда.
RUN set -eux; \
    mkdir -p storage/app/media storage/app/private storage/app/public storage/app/cache \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache/views; \
    COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev; \
    rm -f /usr/bin/composer; \
    CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan optimize:clear; \
    CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan view:cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    mkdir -p /data/caddy /config/caddy && chown -R www-data:www-data /data /config

USER www-data

# Проверка ходит на внутренний порт и получает ответ от php (`health: '/up'`).
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:2020/up >/dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--caddyfile=/etc/frankenphp/Caddyfile", "--host=0.0.0.0", "--port=80", "--admin-port=2019", "--max-requests=500"]
