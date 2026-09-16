# CLAUDE.md

Документация, коммиты, комментарии и ответы — **по-русски**.

## Что это

**xcar-new** — xcar.ru заново: оптовая площадка автомобилей как веб-приложение
для телефона. Один Laravel на три хоста — три PWA одного вида: `xcar.ru`
(витрина, кабинет `/lk`, вход), `crm.xcar.ru` (сотрудники: предложения,
галерея, работа — сделки · почта · чаты, закупки, настройки; пути без
`/admin`, старые `xcar.ru/admin/*` → 301), `park.xcar.ru` (стоянка).
Поверхность — `App\Support\Surface`, ставит `ResolveSurface` по хосту; чужие
маршруты на хосте — 404. **Сайт закрыт, саморегистрации нет**: новый человек
приходит только по пригласительной ссылке `/i/{code}`; прежний допуск по
заявке (`SiteWall`, `DecideAccess`, Telegram `Registration`) в коде остался,
но не используется. Без Filament и чужих админ-фреймворков — свой UI-кит.

Старый проект `../xcar.ru` — донор, не источник. Подробности по модулям и
решения владельца — `notes/*.md` (главное — `notes/moduli-podrobno-2026-09-16.md`).

## Стек и принципы

- Laravel 13, PHP 8.5, Postgres 18 (данные, очередь, сессии, кэш),
  FrankenPHP/Octane, Mercure внутри Caddy. Blade + Turbo + Stimulus
  (`resources/js/controllers/<имя>_controller.js`), никаких Alpine/Livewire/Vue.
- Изменение состояния — Action + событие; контроллеры и экраны в модели не
  пишут. Событие → Mercure → Turbo Stream, никаких `poll`. POST всегда
  отвечает редиректом. Код по областям `app/{Offers,Mail,Park,Purchases,Users,
  Cars,Media,Live,Chats,Telegram,Workflow,Notifications,Push,Storage}`, HTTP по
  поверхностям `app/Http/{Site,Cabinet,Admin,Park,Auth}`, маршруты
  `routes/{web,crm,park,auth}.php`.
- Телефон — основное устройство: таб-бар, шторки `<dialog>` (`sheet.js`),
  safe-area, 16px в полях, системные `<select>`/`<input type=date>`.
  Проверка — глазами, с телефона (Browser pane 375 px), тесты во время
  разработки не пишем — только когда ошибка молчалива и дорога.
- Один язык вида на витрину, кабинет, CRM и стоянку: классы кита и девять
  правил — в шапке `resources/css/app.css` (лайм — глагол; тёмное — только
  хром; теней и обводок нет; скругления 24/16/12/8; заголовки 500; подписей
  под элементами нет; числа табличные; пустой блок не рисуется). Шапка,
  таб-бар и плашка действий — одно стекло (`--bar-alpha` + размытие, капсулы —
  тон поверх), глухих полос и теней у них нет. Тёмная тема —
  `.dark` в CSS, утилит `dark:` нет. CRM и стоянка плотнее (`surface-crm|park`).
- Оболочка: `x-ui.shell` (шапка, `h1` + `actions`, «назад» ставит сам по
  `Nav::backFor`), `x-ui.cabinet` (пилюли разделов), `x-ui.toolbar`
  (сортировка, пилюли, `extra`, фильтры в шторке; всё состояние — в адресе,
  `data-turbo-action="replace"`), `x-offer.card` в `.cards`, страница объекта
  с правой колонкой 22rem и `x-ui.action-bar` (телефон — плашка над
  таб-баром, десктоп — парящие кнопки). Подтверждения — `data-turbo-confirm`
  (`confirm.js`), не `window.confirm`. Ощущение приложения — см.
  `notes/prilozhenie-2026-09-13.md`.
- **Экран — как в приложении:** одно главное действие экрана — в
  `x-ui.action-bar`, не иконкой в тулбаре; человек и группа — шапка-контакт
  `x-ui.contact` (кружок, имя-`h1`, чипы, ряд круглых действий `.acts` с
  подписью); списки людей — `.row` (аватар 44, имя, `.row-sub` чипами ≤3);
  машины внутри кабинета — строками (`cabinet/buyers/offer-row`), полная
  карточка только в ленте; выбор людей — `.row-check` с галкой справа, машин —
  `.pick-card`; пустое состояние — одна фраза; на десктопе широкой строке —
  вторая колонка, а не всё вниз.
- Факты не склеиваются через « · » — чипами `.tag`/`.chip`, человек —
  `x-ui.person`, номер — `nums`-чипом. Одно действие у элемента — элемент и
  есть кнопка с подтверждением, не кнопка рядом. Подписей-пояснений нет:
  непонятный блок переделывается. Вторичное не крупнее основного.
- **Цена не разрывается:** разряды и ₽ — неразрывными пробелами через
  `Support\Money::nums/rub` (голого `number_format(…, ' ')` в Blade нет);
  «от → до» переносится только целыми частями, `fit_controller` ужимает шрифт
  лишь когда не влезает неразрывный кусок. `.nums` — только у цен, счётчиков и
  таймеров.
- Словарь: слова «ставка» нет; менеджер **подтверждает** предложение ценой
  («подтверждения»); покупатель **проявляет интерес**; менеджер **открывает**
  предложения покупателям («показ»); «предложение» — только машина;
  «автомобиль», не «машина», в текстах. Слова «битые» нет никогда.
- VIN — `App\Cars\Vin` + память базы `VinMemory` (память важнее таблиц; год из
  VIN не подставляется, если марка его не кодирует).

## Роли, цены, покупатели

Роли `Users\Role`: `admin`/`moderator` — сотрудники (CRM); `manager` —
внешний партнёр: подтверждает ценой, ведёт сделки, закупки и **своих
покупателей** в кабинете на сайте; `buyer` — покупатель менеджера: видит
только открытое ему, одну цену и «Проявить интерес»; `visitor` — мёртвая.
Способности — методы `Role` явными списками (`canBid`, `canSeePurchases`,
`canSeeGallery`, `canChat`, `canShare`, `canInterest`).

**Три цены оффера:** `floor_price` закупочная (только сотрудник),
`publish_price` заявленная (пусто — равна закупочной; менеджер считает её
закупочной, `Offer::declaredPrice()`, от неё `minBid()`), `asking_price`
продажи. Кто что видит — одна дверь `Offers\PriceView::for($offer, $user)`.

**Кто что видит — одна дверь** `Offer::scopeVisibleTo(?User)` / `isVisibleTo()`:
сотрудник всё; менеджер — Open/Gallery своего круга (`managers_limited` +
`offer_managers`, по умолчанию все); покупатель — Open с показом его
менеджера (лично или через группу); гость — ничего. Через неё идут каталог,
страница, live-фрагменты, PDF, подтверждение, интерес, избранное, счётчики.

**Покупатели.** `login` (латиница; вход одним полем логин / телефон / почта),
`manager_id`, `contact_fields` (что покупатель вправе указывать — снимок с
приглашения). Приглашение `Users\Invite` (`/i/{code}`): менеджер зовёт
покупателей (многоразовая, `fields`, группа), админ — в CRM «Пользователи →
Ссылка» и в кабинете сайта `/lk/priglasheniya` (`IssueAdminInvite`) — менеджера (`role` manager, `max_uses` 1, `ManagerJoined`) или
покупателя от имени менеджера. `AcceptInvite` даёт `approved_at` сразу.
Группы `BuyerGroup`; показы `Offers\Showing` (ровно одно из user/group),
`ShowOffers`/`HideOffers`, покупателю одно уведомление на пачку, live —
`refresh` в `user/{id}` (тему `catalog` покупатель не слушает). Интерес
покупателя — `BuyerInterestNotice` его менеджеру. Ссылка на новый пароль —
`IssuePasswordLink` (`/parol/ssylka/{token}`, сутки, одноразовая) — менеджер
покупателю, админ кому угодно. Админ передаёт покупателя `TransferBuyer`.
Форма в шторке после успешного POST закрывается сама (`app.js`).

## Ключевые двери и договорённости

- Состояния оффера — только `Workflow\ChangeOfferState`. **Приём
  подтверждений — срок, не состояние**: `Offer::bidsOpen()`/`closed()` по
  `bids_close_at`, продление «+15 мин / +1 ч»; у закупок так же.
- Шеринг PDF — `Offers\Share` (`Subject`, `SharePdf`, `Caption`), тумблер
  `share_locked` серит «Поделиться», PDF — 422, кадры под водяным знаком
  (`Media\Watermark`, `StampPhoto`/`UnstampPhoto`, `media:restamp` после
  перерисовки знака). Share отдаёт только файл — файл и текст вместе теряют файл.
- Почта: письмо целиком не скачивается (`BODYSTRUCTURE`), ветка → машина
  одной дверью `LinkThread`, файлы — `ImportThreadFiles`.
- Закупки Carcade: витрина — две карточки на закупку (легковые/грузовые), CRM
  — один список с фильтрами в адресе, оценка `…/{car}/ocenka`, выгрузка — тот
  самый файл Carcade (`Purchases\Export`); повторной выкачки фото нет нигде.
- Telegram-бот владельца без SDK (`Telegram\Bot`, IPv6, `telegram:poll`).
- Медиа: `PhotoIngest` — всё входящее в 1600 px webp, `sha` исходника у
  каждого кадра; вход по ключу — `laragear/webauthn` с провайдером
  `xcar-webauthn`, клиент `passkey_controller` на `@simplewebauthn/browser`.

## Команды

```bash
./serve-local.sh                 # http://xcar.localhost:8010 (php@8.5 и postgres из brew)
php artisan queue:work database-long --queue=long,mail --stop-when-empty   # почта, фото, закупки
php artisan queue:work --stop-when-empty                                   # конверсии, уведомления
php artisan offers:tick | mail:sync | mail:reconcile | notifications:digest | push:keys | media:restamp
npm run build                    # ассеты; npm run dev — с горячей перезагрузкой
node scripts/icons.mjs           # иконки, экраны запуска, водяной знак из resources/icons
./deploy/deploy.sh               # выкладка: снимок git → сборка → бэкап базы → миграции → up
./deploy/deploy.sh backup|check|artisan …|rollback TAG
```

Вход глазами без пароля — `/dev/vhod/{id}` (только local). Хосты локально как
в бою: `xcar.localhost`, `crm.xcar.localhost`, `park.xcar.localhost` :8010,
cookie на `.xcar.localhost`. База `xcar_new` (xcar/xcar). Почта локально —
`scripts/imapserver.py` (1143) и mailpit (1025/8025); Mercure локально нет.

## Диски

`media` (публичное, Caddy отдаёт `/media/*`), `private` (письма, документы,
чаты, чистые копии кадров — только через контроллеры), `cache`
(воспроизводимое: PDF, части писем, tmp — не бэкапится, `storage:gc`).
Что можно пересчитать — в `cache`, что нельзя — в `private` или `media`.

## Сервер

`ssh xcar-next` (root@200.169.180.17), см. `CONNECT.md`; пароли —
`CONNECT.local.md` вне git. Раскладка `/srv/xcar/{env,releases,current}`,
данные на втором диске `/srv/xcar/data/*`, бэкапы — restic в S3
(`deploy/backup.sh`). Домены `xcar.ru`, `crm.xcar.ru`, `park.xcar.ru`. Старый
сервер `xcar-new` (201.24.57.130) заморожен с 12.09.2026.
**Прод не трогать без спроса, выкладка только `deploy/deploy.sh`.**
