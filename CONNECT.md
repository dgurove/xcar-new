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

Три приложения на трёх именах. Пока без домена — через nip.io (резолвится в
тот же IP без своего DNS): `http://200.169.180.17.nip.io` (сайт),
`http://crm.200.169.180.17.nip.io` (CRM), `http://park.200.169.180.17.nip.io`
(стоянка); голый `http://200.169.180.17` уводит 301 на имя nip.io — на IP
cookie не принимаются и вход не держится; `https://` по IP не работает,
сертификата на адрес не бывает. Итог — `xcar.ru`, `crm.xcar.ru`, `park.xcar.ru`; при
переезде на домен меняются `SITE_ADDRESSES` в `.env` и `APP_URL`, `CRM_HOST`,
`PARK_HOST`, `SESSION_DOMAIN`, `WEBAUTHN_*` в `.env.app`.
