# Доступ

Сервер: `ssh xcar-next` → `root@200.169.180.17`, ключ `~/.ssh/id_ed25519`.
Парольный вход выключен `deploy/server-setup.sh`; пароль root — в
`CONNECT.local.md` (вне git).

Раскладка: `/srv/xcar/env/{.env,.env.app}` (настройки, не в git),
`/srv/xcar/releases/<tag>` (снимки), `/srv/xcar/current` → текущий.
Данные — второй диск 100 ГБ на `/srv/xcar/data`: `postgres/`, `media/`
(фото, Caddy отдаёт сам), `private/` (письма, документы), `cache/`
(воспроизводимое, не бэкапится), `storage/` (рабочие каталоги Laravel),
`backups/` (дампы базы за семь дней). Корневой диск — система и Docker.

## Бэкапы

Ночью в 03:20 `deploy/backup.sh`: дамп базы локально, снимки базы и файлов
(без конверсий) в restic на S3 Timeweb (`RESTIC_*`, `AWS_*`, `S3_*` в
`/srv/xcar/env/.env`; ключи и пароль репозитория — в `CONNECT.local.md`),
ротация 14 дневных / 8 недельных / 6 месячных, по воскресеньям prune и
check, журналы контейнеров за день — в `logs/ГГГГ/ММ/ДД` того же бакета,
старше 90 дней стираются. `check.sh` ругается, если снимку больше суток.

Восстановить: `restore.sh db latest` (или путь к дампу), `restore.sh
files [каталог]`, посмотреть снимки — `restore.sh snapshots`.
Перед миграцией `deploy.sh` снимает только локальный дамп.

Три приложения на трёх именах: `https://xcar.ru`, `https://crm.xcar.ru`,
`https://park.xcar.ru` (с 12.09.2026; сертификаты Caddy выпускает сам, ACME
на `dev@dgurov.com`). `api.xcar.ru`, голый IP и имена nip.io — 301 на
`https://xcar.ru`. Cookie сессии на `.xcar.ru` — один вход на всех трёх.
`www.xcar.ru` в DNS пока нет — когда появится, дописать в `REDIRECT_ADDRESSES`.

Telegram-бот владельца (регистрации с кнопками решения): `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_OWNER_CHAT_ID` в `.env.app`; те же значения в `.env` как
`TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` для `check.sh`. До `api.telegram.org`
с сервера доходит только IPv6 — сеть compose с `enable_ipv6`, смена сети
требует `compose down` и `up`.

Старый сервер `ssh xcar-new` (201.24.57.130) заморожен 12.09.2026: приложение
в `artisan down`, очереди остановлены, дамп базы на нём —
`/var/backups/xcar/db-pereezd-20260912.dump`. Не удалять до 20.09.2026.
