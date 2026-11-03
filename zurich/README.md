# zurich — выход прода в Telegram и WhatsApp

Россия блокирует Telegram, поэтому прод (`xcar-next`, Питер) сам к нему не ходит. Все запросы обоих ботов идут через
сервер zurich (188.190.4.62). Логика ботов остаётся на проде: каждое нажатие работает с базой xcar. Здесь только «голос».

## Как устроено

    контейнер xcar ──TLS──▶ zurich:9443 (= 172.18.0.1:9443 на проде)
        ══ обратный SSH-туннель, его держит zurich ══▶ api.telegram.org:443

- `systemd/xcar-tunnel.service` — служба `systemctl --user` на zurich. Это `ssh -R 172.18.0.1:9443:api.telegram.org:443`
  под пользователем `xcar-tunnel` на проде.
- 172.18.0.1 — шлюз сети compose (подсеть закреплена в `deploy/compose.yaml`), в контейнерах он зовётся `zurich`
  (`extra_hosts`).
- Приложение подключается к `zurich:9443` вместо api.telegram.org (`TELEGRAM_VIA=zurich:9443`,
  `App\Telegram\Bot::routeOptions`, `CURLOPT_CONNECT_TO`). Имя в запросе и сертификат остаются Telegram: TLS идёт от прода
  до Telegram, zurich видит одни байты.
- На диск zurich ничего не пишется, чистить нечего.
- Почему SSH, а не WireGuard или VPN: из России их режут, а SSH из Цюриха на прод работает. Почему опрос, а не вебхук:
  вебхук открыл бы вход на прод снаружи, а обновления Telegram и так держит сутки.

## Поставить заново

1. На zurich: `./zurich/install.sh` — заведёт ключ `~/.ssh/xcar_tunnel` (если нет) и службу.
2. С zurich: `./deploy/server-setup.sh tunnel` — на проде пользователь `xcar-tunnel` с этим ключом (`restrict`, слушать
   можно только `172.18.0.1:9443`), правило sshd `GatewayPorts clientspecified` только для него, ufw пускает к порту
   только контейнеры (`172.18.0.0/16`).
3. В `/srv/xcar/env/.env.app` на проде: `TELEGRAM_VIA=zurich:9443`.
4. WhatsApp — ниже.

## Проверить

    systemctl --user status xcar-tunnel                    # zurich: служба активна
    ssh xcar-next 'ss -ltn | grep 9443'                    # прод: слушает 172.18.0.1:9443
    ssh xcar-next 'docker exec xcar-app-1 curl -sS --connect-to api.telegram.org:443:zurich:9443 https://api.telegram.org/ -o /dev/null -w "%{http_code}\n"'

`deploy/check.sh` на проде каждый раз проверяет `zurich:9443` из контейнера. Лёг туннель — тревога уходит владельцу
напрямую по IPv6: через туннель о нём не сказать. Служба на zurich перезапускается сама каждые 5 с без предела.

## WhatsApp: предложения в группу с номера владельца (03.11.2026)

Тот же туннель несёт второй проброс: `172.18.0.1:9444` на проде → `127.0.0.1:9480` здесь, служба `xcar-whatsapp`
(`whatsapp/main.go`, whatsmeow). Это связанное устройство номера владельца («Chrome (Linux)» в «Связанных устройствах»),
которое умеет одно — отправить сообщение в группу из `TARGET`. Что и когда слать, решает прод (`App\WhatsApp\Group`):
каждое новое предложение, паузы 30–90 с, ночью тишина. PDF проходит через память, на диске — только
сессия (`~/.local/share/xcar-whatsapp/session.db`, права 600, несколько МБ).

Поставить:

1. Go — в `~/.local/go` (официальный архив с go.dev). `./zurich/install.sh` соберёт `~/.local/bin/xcar-whatsapp`, заведёт
   `~/.config/xcar-whatsapp/env` (`TOKEN` — случайный, `TARGET` — пусто) и службы.
2. Привязать: `~/.local/bin/xcar-whatsapp pair <номер владельца>` при остановленной службе — код из 8 знаков уходит
   владельцу в Telegram, он вводит его: WhatsApp → Связанные устройства → Привязка устройства → «Связать по номеру
   телефона». Затем `systemctl --user restart xcar-whatsapp`.
3. Группа: `curl -s -H "Authorization: Bearer $(sed -n 's/^TOKEN=//p' ~/.config/xcar-whatsapp/env)" 127.0.0.1:9480/groups`
   → JID нужной в `TARGET`, перезапуск службы.
4. На проде в `/srv/xcar/env/.env.app`: `WHATSAPP_VIA=http://zurich:9444`, `WHATSAPP_TOKEN=<TOKEN отсюда>`.
   `WHATSAPP_SINCE` пуст — сами предложения не уходят, только проверка `php artisan whatsapp:test <номера>`; задать
   (`2026-11-05 10:00`) — с этого момента публикации уходит каждое новое. `WHATSAPP_DAILY` — потолок в сутки (100).

Проверить:

    systemctl --user status xcar-whatsapp
    ssh xcar-next 'docker exec xcar-app-1 php artisan whatsapp:status && echo в порядке'

**Остановилась сама** (отвязали, временный бан, сессию перехватили, клиент устарел, WhatsApp отказал в подключении) — пишет владельцу в Telegram, причина в
`~/.local/state/xcar-whatsapp/stopped`, и больше не подключается. Разобраться, в чём дело (при бане — подождать срок и
снизить поток), потом удалить этот файл и `systemctl --user restart xcar-whatsapp`; отвязали — удалить
`~/.local/share/xcar-whatsapp` и привязать заново. Не отвязывать устройство на телефоне без нужды: частые привязки
WhatsApp замечает. Телефон владельца должен выходить в сеть хотя бы раз в 14 дней, иначе WhatsApp отвяжет устройство сам.
whatsmeow обновлять раз в месяц и после странных ошибок: `go get go.mau.fi/whatsmeow@latest && go mod tidy` в
`whatsapp/`, затем `install.sh`.
