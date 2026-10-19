<?php

return [
    // Хосты. CRM, стоянка и логистика — поддомены того же приложения; сайт — хост app.url (гараж — его раздел /garage).
    'crm_host' => env('CRM_HOST', 'crm.localhost'),
    'park_host' => env('PARK_HOST', 'park.localhost'),
    'logistics_host' => env('LOGISTICS_HOST', 'logistics.localhost'),
    // Короткие имена логистики (`log.`, `l.`, `go.` у хоста сайта) — 301 на неё (`RedirectLegacyPaths`); в `SITE_ADDRESSES`
    // прода они есть только ради сертификата.
    'logistics_aliases' => array_filter(explode(',', (string) env('LOGISTICS_ALIASES', 'log,l,go'))),

    // ООО «ПРАЙМ» (`Seller::Prime`): счета сделок и гаража, страница «О компании» — по карточке предприятия.
    'company' => [
        'name' => 'ООО «ПРАЙМ»', 'full_name' => 'Общество с ограниченной ответственностью «ПРАЙМ»',
        'inn' => '5007110932', 'kpp' => '500701001', 'ogrn' => '1205000074949',
        'address' => 'Московская область, г. Дмитров, мкр. Владимира Махалина, д. 20, офис 228',
        'phone' => '+7 495 669-11-11', 'email' => '6691111@mail.ru',
        'bank' => 'ПАО Сбербанк', 'bik' => '044525225', 'account' => '40702810340000004750', 'corr_account' => '30101810400000000225',
        'director' => 'Кузнецов А. В.', 'director_full' => 'Кузнецов Андрей Викторович',
        // ДКП (19.10.2026): «в лице …» готовым родительным падежом и префикс номера договора «ПР-15/2026».
        'signed_by' => 'Генерального директора Кузнецова Андрея Викторовича', 'contract_prefix' => 'ПР',
        // УСН «доходы минус расходы» с НДС 5 % (ст. 164 п. 8 НК): одна ставка на все продажи ПРАЙМ — сделки и гараж.
        // Межценовой разницы при 5 % нет: НДС внутри всей суммы счёта (по умолчанию — «в сумме», сверху — галкой у контрагента).
        'vat_rate' => (int) env('XCAR_VAT_RATE', 5),
    ],

    // ИП Кузнецов (`Seller::Park`): счета, акты хранения, акты приёма и выдачи, договоры парковки.
    // Ключи те же, что у ПРАЙМ; ogrn — ОГРНИП. Пустое печатается прочерком, без банка в счёте нет QR.
    'park_company' => [
        'name' => 'ИП Кузнецов Андрей Викторович', 'full_name' => 'Индивидуальный предприниматель Кузнецов Андрей Викторович',
        'inn' => null, 'ogrn' => null, 'address' => null, 'phone' => null, 'email' => null,
        'bank' => null, 'bik' => null, 'account' => null, 'corr_account' => null,
        'director' => 'Кузнецов А. В.', 'director_full' => 'Кузнецов Андрей Викторович',
        // УСН с НДС 5 % (решение владельца 30.09.2026): ставка на все счета парковки — вендору и покупателю.
        'vat_rate' => (int) env('XCAR_PARK_VAT_RATE', 5),
    ],

    // ООО «Р.О.С.» (`Seller::Ros`, 10.10.2026): только сделки АльфаСтрахования с НДС — покупатель платит ему, оно
    // переводит Альфе. Реквизиты — из шаблона ДКП владельца (19.10.2026); живут в строке `billing_parties` с seller = ros
    // («Реквизиты»), конфиг — только для первой строки и пустых полей.
    'ros_company' => [
        'name' => env('ROS_NAME', 'ООО «Р.О.С.»'), 'full_name' => env('ROS_FULL_NAME', 'Общество с ограниченной ответственностью «Р.О.С.»'),
        'short' => 'Р.О.С.',
        'inn' => env('ROS_INN', '9704056013'), 'kpp' => env('ROS_KPP', '770401001'), 'ogrn' => env('ROS_OGRN', '1217700139568'),
        'address' => env('ROS_ADDRESS', '121099, г. Москва, вн. тер. г. муниципальный округ Арбат, ул. Новый Арбат, д. 27, помещ. 7Б/П'),
        'phone' => env('ROS_PHONE'), 'email' => env('ROS_EMAIL'),
        'bank' => env('ROS_BANK', 'АО «АЛЬФА-БАНК»'), 'bik' => env('ROS_BIK', '044525593'), 'account' => env('ROS_ACCOUNT', '40702810701810001836'), 'corr_account' => env('ROS_CORR_ACCOUNT', '30101810200000000593'),
        'director' => env('ROS_DIRECTOR', 'Кузнецова Е. А.'), 'director_full' => env('ROS_DIRECTOR_FULL', 'Кузнецова Екатерина Андреевна'),
        'signed_by' => 'Генерального директора Кузнецовой Екатерины Андреевны', 'contract_prefix' => 'РОС',
        // Альфа продаёт с НДС — и Р.О.С. перепродаёт с НДС: 22 % (владелец 11.10.2026).
        'vat_rate' => (int) env('ROS_VAT_RATE', 22),
    ],

    // Стоянка: после скольких дней хранения без движения ТС попадает в «стоят долго».
    'park_idle_days' => 30,          // стоит долго: с этого дня тревожно, с удвоенного — красно
    'system_account' => env('XCAR_SYSTEM_ACCOUNT', 'deal'), // slug ящика для «Забыл пароль»
    'notify_account' => env('XCAR_NOTIFY_ACCOUNT'), // slug ящика для писем-уведомлений сотрудникам; пусто — первый ящик парковки
    'park_docs_days' => 3,           // бумаги вендору не отправлены столько дней после приёма — дело дня
    // «✨ Распознать»: скрипт deploy/bin/ocr (в образе — /usr/local/bin/ocr), локально — путь к обёртке над venv.
    'ocr' => env('XCAR_OCR', 'ocr'),
    // Чужой водяной знак с фото предложения (Мигторг): deploy/bin/unmark, реестр знаков — resources/watermarks.
    'unmark' => env('XCAR_UNMARK', 'unmark'),

    // Хаб живых обновлений (Mercure внутри Caddy). Пусто — публикация молча пропускается.
    'mercure' => [
        'hub' => env('MERCURE_HUB_URL'),
        'publisher_key' => env('MERCURE_PUBLISHER_KEY'),
        'subscriber_key' => env('MERCURE_SUBSCRIBER_KEY'),
    ],

    // Ключи Web Push (VAPID): php artisan push:keys.
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:proverka@xcar.ru'),
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
    ],

    // Бот xcar: владельцу и привязанным админам — сообщения с кнопками решения, менеджерам — сделки и вход.
    // chat_id владельца бот подсказывает сам — напишите ему; имя бота без настройки берётся у Telegram (getMe).
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'owner_chat_id' => env('TELEGRAM_OWNER_CHAT_ID'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        // На сервере до api.telegram.org доходит только IPv6; локально на маке его может не быть.
        'ipv6' => (bool) env('TELEGRAM_IPV6', true),
        // Бот предложений менеджерам (@xcar_offers_bot, 03.10.2026): свой токен, постоянный сервис `offers-bot`.
        // lanes — сколько чатов разбираются параллельно; login — кнопки «Открыть в XCar» входом через Telegram
        // (включать после /setdomain xcar.ru в BotFather: без него Telegram отклоняет всё сообщение с кнопкой).
        'offers' => [
            'token' => env('TELEGRAM_OFFERS_BOT_TOKEN'),
            'username' => env('TELEGRAM_OFFERS_BOT_USERNAME'),
            'lanes' => (int) env('TELEGRAM_OFFERS_LANES', 3),
            'login' => (bool) env('TELEGRAM_OFFERS_LOGIN', false),
        ],
    ],

    // Доступ менеджеров (07.10.2026, владелец: «менеджерам видны закупочные цены, это очень опасно»): одно устройство,
    // вход только через Telegram, окно в `minutes` минут, потом экран замерзает до кода или кнопки в боте. Admin-роль и
    // «Войти как» не касается. `LOCK_MANAGERS=false` — выключить разом, не выкладывая код.
    'lock' => [
        'enabled' => (bool) env('LOCK_MANAGERS', true),
        'minutes' => (int) env('LOCK_MINUTES', 15),
    ],

    // Оплата по ссылке — ЮKassa, подключённая в СберБизнесе; чеки 54-ФЗ пробивает она же («Чеки от ЮKassa»).
    // Чек уходит только на почту; позиция — услуга, полный расчёт (`YooKassa::receipt`), код НДС — от ставки счёта (`Billing\Vat::receiptCode`).
    'yookassa' => [
        'url' => env('YOOKASSA_URL', 'https://api.yookassa.ru/v3'),
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret' => env('YOOKASSA_SECRET_KEY'),
        'receipt' => (bool) env('YOOKASSA_RECEIPT', true),
        // ИНН НКО «ЮМани»: её перечисления на расчётный счёт — уже учтённые оплаты по ссылкам, выписка их не привязывает.
        'payout_inn' => env('YOOKASSA_PAYOUT_INN', '7750005725'),
        // Сколько можно заплатить одной ссылкой: СБП и SberPay — 700 000 ₽ за платёж (карта — 350 000).
        'max_amount' => (int) env('YOOKASSA_MAX_AMOUNT', 700000),
    ],

    // Sber API (СберБизнес): выписка по расчётному счёту — оплаты по счетам отмечаются сами.
    // Вход — OAuth через СберБизнес ID под директором, запросы — с сертификатом клиента (mTLS).
    'sber' => [
        'auth_url' => env('SBER_AUTH_URL', 'https://sbi.sberbank.ru:9443/ic/sso/api/v2/oauth/authorize'),
        'api_url' => env('SBER_API_URL', 'https://fintech.sberbank.ru:9443'), // песочница — https://fintech-test.sberbank.ru:9443
        'client_id' => env('SBER_CLIENT_ID'),
        'client_secret' => env('SBER_CLIENT_SECRET'), // живёт 40 дней; `bank:secret` меняет на бессрочный и хранит в базе
        'scope' => env('SBER_SCOPE', 'openid GET_STATEMENT_ACCOUNT'),
        'cert' => env('SBER_CERT'),                  // p12 или pem клиента
        'cert_password' => env('SBER_CERT_PASSWORD'),
        'ca' => env('SBER_CA'),                      // корневой «Russian Trusted Root CA»; пусто — системный набор
    ],

    // Выписка СберБизнеса: уведомление «Поступление без счёта» и дайджест владельцу. Выключены, пока связь не настроена.
    'bank' => [
        'notify' => (bool) env('BANK_NOTIFY', false),
    ],

    // Исходящий прокси для carcade.com: адрес прода у них в бане.
    'carcade_proxy' => env('CARCADE_PROXY'),

    // migtorg.com: открытый список лотов и вход за полным набором фото совпавшего (`Offers\Migtorg`).
    'migtorg_email' => env('MIGTORG_EMAIL'),
    'migtorg_password' => env('MIGTORG_PASSWORD'),
    'migtorg_proxy' => env('MIGTORG_PROXY'),

    // Слот публикации — каждый день в 16:00 МСК; приём подтверждений — через 3 дня после выхода, до 17:00
    // (владелец 03.10.2026, `Offers\Slots`). Напоминание перед сроком этапа.
    'slot_hour' => 16,
    'bids_window_days' => 3,
    'bids_close_hour' => 17,
    // Черновик вендора с `to_gallery` висит в галерее столько дней, до 23:59 последнего (владелец 08.10.2026).
    'gallery_days' => 3,
    'remind_before_minutes' => 60,
];
