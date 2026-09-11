# Доступ

Сервер: `ssh xcar-next` → `root@200.169.180.17`, ключ `~/.ssh/id_ed25519`.
Парольный вход выключен `deploy/server-setup.sh`; пароль root — в
`CONNECT.local.md` (вне git).

Раскладка: `/srv/xcar/env/{.env,.env.app}` (настройки, не в git),
`/srv/xcar/releases/<tag>` (снимки), `/srv/xcar/current` → текущий.

Пока без домена: `http://200.169.180.17`. Итог — `xcar.ru`, `park.xcar.ru`.
