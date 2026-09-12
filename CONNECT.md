# Доступ

Сервер: `ssh xcar-next` → `root@200.169.180.17`, ключ `~/.ssh/id_ed25519`.
Парольный вход выключен `deploy/server-setup.sh`; пароль root — в
`CONNECT.local.md` (вне git).

Раскладка: `/srv/xcar/env/{.env,.env.app}` (настройки, не в git),
`/srv/xcar/releases/<tag>` (снимки), `/srv/xcar/current` → текущий.

Три приложения на трёх именах. Пока без домена — через nip.io (резолвится в
тот же IP без своего DNS): `http://200.169.180.17.nip.io` (сайт),
`http://crm.200.169.180.17.nip.io` (CRM), `http://park.200.169.180.17.nip.io`
(стоянка); голый `http://200.169.180.17` тоже отвечает как сайт, но с
отдельной сессией. Итог — `xcar.ru`, `crm.xcar.ru`, `park.xcar.ru`; при
переезде на домен меняются `SITE_ADDRESSES` в `.env` и `APP_URL`, `CRM_HOST`,
`PARK_HOST`, `SESSION_DOMAIN`, `WEBAUTHN_*` в `.env.app`.
