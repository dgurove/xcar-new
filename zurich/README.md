# zurich — выход прода в Telegram

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

## Проверить

    systemctl --user status xcar-tunnel                    # zurich: служба активна
    ssh xcar-next 'ss -ltn | grep 9443'                    # прод: слушает 172.18.0.1:9443
    ssh xcar-next 'docker exec xcar-app-1 curl -sS --connect-to api.telegram.org:443:zurich:9443 https://api.telegram.org/ -o /dev/null -w "%{http_code}\n"'

`deploy/check.sh` на проде каждый раз проверяет `zurich:9443` из контейнера. Лёг туннель — тревога уходит владельцу
напрямую по IPv6: через туннель о нём не сказать. Служба на zurich перезапускается сама каждые 5 с без предела.
