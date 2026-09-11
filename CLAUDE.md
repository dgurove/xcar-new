# CLAUDE.md

Документация, коммиты и комментарии — **по-русски**, отвечать по-русски.

## Что это

**xcar-new** — xcar.ru заново: оптовая площадка автомобилей как
веб-приложение для телефона. Один Laravel на два хоста: `xcar.ru` (витрина,
кабинет `/lk`, админ `/admin` как раздел сайта) и `park.xcar.ru` (стоянка).
Без Filament и любого чужого админ-фреймворка — свой UI-кит на одних токенах.

Старый проект `../xcar.ru` — донор, не источник: оттуда берутся проверенные
решения (почта mail.ru, VIN, Carcade, приём фото, шеринг PDF, Mercure, модель
маршрута), переписываются в новую структуру. План — `~/.claude/plans/tender-jumping-quokka.md`.

## Стек и принципы

- Laravel 13, PHP 8.5, Postgres 18 (данные, очередь, сессии, кэш), FrankenPHP
  в режиме воркера (Octane), хаб Mercure внутри Caddy.
- Blade + Hotwire: Turbo (переходы, фреймы, стримы) и Stimulus (поведение).
  Контроллеры — `resources/js/controllers/<имя>_controller.js`, подключаются по
  имени файла. Никаких Alpine/Livewire/Vue.
- Каждое изменение состояния — Action + событие; контроллеры и экраны в
  модели не пишут. Событие → Mercure → Turbo Stream, никаких `poll`.
- Код по предметным областям: `app/Offers`, `app/Mail`, `app/Park`,
  `app/Purchases`, `app/Users`, `app/Cars`, `app/Media`, `app/Live`.
  HTTP по поверхностям: `app/Http/{Site,Cabinet,Admin,Park}`.
- Телефон — основное устройство: таб-бар, шторки `<dialog>`, камера в поле,
  16px в полях, safe-area. Системные `<input type=date>`, `<select>`.
- Вид — язык витрины xcar.ru, один на витрину, кабинет, админку и стоянку:
  шапка с квадратами (телефон) и рядами капсул (десктоп), нижний таб-бар
  с аватаром в «Кабинете», подвал, крошки, `h1` + ряд действий, тулбар списка
  (`x-ui.toolbar`: сортировка · пилюли · вид · фильтры в шторке), одна
  карточка на строку и плитку (`x-offer.card` в `.cards`), страница объекта
  с правой колонкой и полосой действий (`x-ui.action-bar`), кабинет с
  пилюлями разделов (`x-ui.cabinet`), вход на фото паркинга. Классы кита и
  девять правил — в шапке `resources/css/app.css`; витрина кита — `/admin/ui`
  (local). Тёмная тема — `.dark` в CSS, утилиты `dark:` не пишем.
- Тесты во время разработки не пишем — только когда без теста ошибка молчалива
  и дорога. Проверка — глазами, с телефона.
- Подписей-пояснений под элементами нет: непонятный блок переделывается.

## Команды

```bash
./serve-local.sh                 # http://localhost:8010 (php@8.5 и postgres из brew)
php artisan queue:work database-long --queue=long,mail --stop-when-empty   # почта, фото, закупки
php artisan queue:work --stop-when-empty                                   # конверсии, уведомления
php artisan offers:tick | mail:sync | mail:reconcile | notifications:digest | push:keys
npm run build                    # ассеты; npm run dev — с горячей перезагрузкой
node scripts/icons.mjs           # иконки PWA из favicon.svg
./deploy/server-setup.sh         # один раз на новый сервер
./deploy/deploy.sh               # выкладка: снимок git → сборка → бэкап базы → миграции → up → таймеры
./deploy/deploy.sh backup|check|artisan …|rollback TAG
```

Локальная база `xcar_new` (роль xcar/xcar, порт 5432). Порт 8010, потому
что 8000 занят старым проектом. Стоянка локально — `http://park.localhost:8010`
(вход отдельный). Почта локально — IMAP-сервер на Twisted
`scripts/imapserver.py (venv с twisted)` (порт 1143, `offer`/`deal` : `parol`, письма
файлами в `imapdrop/<user>/`) и `mailpit` (SMTP 1025, веб 8025); хаба Mercure
локально нет — live проверяется на сервере.

## Модули

`app/Offers` (оффер, ставки, сделки, шеринг PDF), `app/Workflow` (страховые
и маршруты по этапам, `ChangeOfferState` — одна дверь состояний),
`app/Mail` (ящики, синк, треды, кандидаты, шаблоны), `app/Chats`, `app/Park`
(стоянка), `app/Purchases` (закупки Carcade), `app/Notifications`
(`Notice` — база, канал database+mail+push), `app/Push`, `app/Live` (Mercure:
`card/refresh/toast/badges`), `app/Media`, `app/Cars`, `app/Users`.
Заметки по этапам — `notes/etap-*.md`.

## Сервер

`ssh xcar-next` (root@200.169.180.17), см. `CONNECT.md`; пароли — в
`CONNECT.local.md` вне git. Раскладка `/srv/xcar/{env,releases,current}`.
Пока без домена — по IP. Прод не трогать без спроса, выкладка только
`deploy/deploy.sh`.
