# zurich — выход прода в Telegram и WhatsApp

Россия блокирует Telegram, поэтому прод (`xcar-next`, Питер) сам к нему не ходит. Все запросы обоих ботов идут через
сервер zurich (188.190.4.62). Логика ботов остаётся на проде: каждое нажатие работает с базой xcar. Здесь только «голос».

## Как устроено

Два независимых пути (03.11.2026, владелец: «туннель регулярно падает, должны быть фоллбеки»), приложение переключается само
(`App\Support\Routes`: не соединилось — тот же запрос следующим путём, лёгший путь две минуты идёт последним):

    контейнер xcar ──TLS──▶ zurich:9443 (= 172.18.0.1:9443 на проде)
        ══ обратный SSH-туннель, его держит zurich ══▶ api.telegram.org:443 (только IPv4)
    контейнер xcar ──TLS──▶ zurich:9445
        ══ SSH-туннель, его держит прод (xcar-tunnel-out) ══▶ 149.154.166.110:443
    и третьим — напрямую (`direct`): блокировка неполная, иногда проходит.

WhatsApp — так же: 9444 (туннель zurich) и 9446 (туннель прода) → служба здесь, 127.0.0.1:9480.
`TELEGRAM_VIA=zurich:9443,zurich:9445,direct`, `WHATSAPP_VIA=http://zurich:9444,http://zurich:9446`.

**Почему «падал туннель»** (03.11.2026): падал не он, а IPv6 отсюда до Telegram — 1 соединение из 8 висело, и запрос с прода
ждал до тайм-аута; сессия при этом жила часами. Лечится `AddressFamily=inet` у ssh — он действует и на пробросы.

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

`deploy/check.sh` на проде каждые 5 минут проверяет из контейнера оба пути (9443, 9445 до Telegram; 9444, 9446 до WhatsApp).
Лёг один — заметка «работает запасной», не чаще раза в 6 часов; беда — только когда не отвечает ни один. Тревога уходит
первым живым путём (9443, 9445, напрямую IPv6, IPv4) и **только владельцу** (Дмитрий): технические сбои админам не шлём
(03.11.2026). Обе службы перезапускаются сами каждые 5 с без предела.

Второй путь (туннель прода): `./deploy/server-setup.sh tunnel-out` — ключ `/root/.ssh/xcar_out` и служба
`xcar-tunnel-out` на проде; его открытый ключ — `zurich/xcar_out.pub`, `./zurich/install.sh` пишет его в
`~/.ssh/authorized_keys` здесь с `restrict,command="/bin/false",permitopen=…` — только два проброса, ни оболочки.

    ssh xcar-next 'systemctl is-active xcar-tunnel-out && ss -ltn | grep -E "944[56]"'
    ssh xcar-next 'docker exec xcar-app-1 curl -sS --connect-to api.telegram.org:443:zurich:9445 https://api.telegram.org/ -o /dev/null -w "%{http_code}\n"'

## WhatsApp: предложения с номера владельца — в группу и в личные (03.11.2026)

Тот же туннель несёт второй проброс: `172.18.0.1:9444` на проде → `127.0.0.1:9480` здесь, служба `xcar-whatsapp`
(`whatsapp/main.go`, whatsmeow). Это связанное устройство номера владельца («Chrome (Linux)» в «Связанных устройствах»),
которое шлёт сообщение в группу из `TARGET` или в личные тому, кто написал первым. Что, кому и когда, решает прод
(`App\WhatsApp`): открытое всем — в группу, открытое не всем и просьбы оценить — менеджеру в личные (владелец,
03.11.2026); паузы 30–90 с, ночью тишина. PDF проходит через память, на диске — только сессия и таблицы `xcar_*`
(`~/.local/share/xcar-whatsapp/session.db`, права 600, несколько МБ).

**Личные — только тем, кто написал первым.** xcar даёт менеджеру ссылку
`https://wa.me/<номер владельца>?text=Подключить XCar: K7P2QM4D`, он её отправляет. Служба ловит личное сообщение с кодом
(`xcar: XXXXXXXX`, 8 букв и цифр, регистр любой): отправителя — в `xcar_allowed` (и номер, и LID, если известны оба),
строку — в `xcar_inbox` (хранится 7 дней), сообщение — «прочитано». xcar забирает строки длинным опросом `/inbox` и
привязывает профиль. Остальные входящие служба не читает и никуда не пишет — это личный WhatsApp владельца.

Запросы (ключ — `Authorization: Bearer TOKEN`, ответ — JSON):

- `GET /status` — `connected`, `logged_in`, `stopped` (причина или `null`), `in_group`, `group` (название), `invite` —
  ссылка-приглашение группы (в памяти час; не дали — прежняя или `null`, переспрос через 10 минут).
- `GET /groups` — группы номера `[{jid, name, members}]`: найти JID для `TARGET`.
- `GET /inbox?after=<id>&wait=<сек, до 50>` — входящие с кодом с `id > after`, по возрастанию, до 100:
  `[{id, jid, phone, name, text, at}]`. `jid` — с номером, если он известен, иначе LID (`…@lid`); `phone` — цифры номера
  или `""`; `name` — имя из профиля WhatsApp; `at` — время сообщения, unix-секунды. Нет строк — ждёт новую до `wait`
  секунд, потом `[]`. `after` больше любого когда-либо выданного `id` — базу завели заново (перепривязка): отдаёт с
  начала, как `after=0`. Работает и у остановленной службы.
- `POST /send` (multipart) — `key` и `caption` обязательны, `pdf`, `filename`, `thumb` (JPEG превью) — по желанию, без
  PDF уходит текст; `to` — личный JID (`…@s.whatsapp.net` или `…@lid`), пусто — группа `TARGET`. Ответ `{id}`; повтор
  того же `key` — тот же `id`, WhatsApp второй раз его не покажет (`key` — один на сообщение и получателя). Ошибки
  `{error}`: 400 — нет `key` или `caption`, PDF не прочитан, `to` не личный JID; 403 «не писал первым» — `to` нет в
  `xcar_allowed`; 503 — остановлена, нет связи, не узнать группу; 409 — номер не в группе; 502 — WhatsApp не принял.
  «Печатает…» — в чате получателя.

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

Проверочная копия — без сессии и на другом адресе: `HOME=$(mktemp -d) LISTEN=127.0.0.1:9481 TOKEN=<16+ знаков>
~/.local/bin/xcar-whatsapp serve`. К WhatsApp она не подключается; копия с боевой сессией перехватила бы её, и служба
остановилась бы сама («сессию перехватил другой клиент»).

**Остановилась сама** (отвязали, временный бан, сессию перехватили, клиент устарел, WhatsApp отказал в подключении) — пишет владельцу в Telegram, причина в
`~/.local/state/xcar-whatsapp/stopped`, и больше не подключается. Разобраться, в чём дело (при бане — подождать срок и
снизить поток), потом удалить этот файл и `systemctl --user restart xcar-whatsapp`; отвязали — удалить
`~/.local/share/xcar-whatsapp` и привязать заново. Не отвязывать устройство на телефоне без нужды: частые привязки
WhatsApp замечает. Телефон владельца должен выходить в сеть хотя бы раз в 14 дней, иначе WhatsApp отвяжет устройство сам.
whatsmeow обновлять раз в месяц и после странных ошибок: `go get go.mau.fi/whatsmeow@latest && go mod tidy` в
`whatsapp/`, затем `install.sh`.
