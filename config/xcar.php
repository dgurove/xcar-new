<?php

return [
    // Хосты. CRM, стоянка и гараж — поддомены того же приложения; сайт — хост app.url.
    'crm_host' => env('CRM_HOST', 'crm.localhost'),
    'park_host' => env('PARK_HOST', 'park.localhost'),
    'garage_host' => env('GARAGE_HOST', 'garage.localhost'),

    // Наше юрлицо: акты парковки, счета (`Party::self`) и страница «О компании» — по карточке предприятия.
    'company' => [
        'name' => 'ООО «ПРАЙМ»', 'full_name' => 'Общество с ограниченной ответственностью «ПРАЙМ»',
        'inn' => '5007110932', 'kpp' => '500701001', 'ogrn' => '1205000074949',
        'address' => 'Московская область, г. Дмитров, мкр. Владимира Махалина, д. 20, офис 228',
        'phone' => '+7 495 669-11-11', 'email' => '6691111@mail.ru',
        'bank' => 'ПАО Сбербанк', 'bik' => '044525225', 'account' => '40702810340000004750', 'corr_account' => '30101810400000000225',
        'director' => 'Кузнецов А. В.', 'director_full' => 'Кузнецов Андрей Викторович',
    ],

    // Стоянка: после скольких дней хранения без движения ТС попадает в «стоят долго».
    'park_idle_days' => 30,          // стоит долго: с этого дня тревожно, с удвоенного — красно
    'notify_account' => env('XCAR_NOTIFY_ACCOUNT'), // slug ящика для писем-уведомлений сотрудникам; пусто — первый ящик парковки
    'park_docs_days' => 3,           // бумаги вендору не отправлены столько дней после приёма — дело дня

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

    // Бот владельца: регистрации с кнопками решения. chat_id бот подсказывает сам — напишите ему.
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'owner_chat_id' => env('TELEGRAM_OWNER_CHAT_ID'),
    ],

    // Оплата по ссылке — ЮKassa, подключённая в СберБизнесе; чеки 54-ФЗ пробивает она же («Чеки от ЮKassa»).
    // Признаки позиции чека — по согласованию с бухгалтером; vat_code: 1 — без НДС, 4 — 20 %, 11 — 22 %.
    'yookassa' => [
        'url' => env('YOOKASSA_URL', 'https://api.yookassa.ru/v3'),
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret' => env('YOOKASSA_SECRET_KEY'),
        'receipt' => (bool) env('YOOKASSA_RECEIPT', true),
        'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
        'vat_code_with_vat' => (int) env('YOOKASSA_VAT_CODE_WITH_VAT', 4),
        'payment_subject' => env('YOOKASSA_PAYMENT_SUBJECT', 'commodity'),
        'payment_mode' => env('YOOKASSA_PAYMENT_MODE', 'full_payment'),
        // ИНН НКО «ЮМани»: её перечисления на расчётный счёт — уже учтённые оплаты по ссылкам, выписка их не привязывает.
        'payout_inn' => env('YOOKASSA_PAYOUT_INN', '7750005725'),
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
        'account' => env('SBER_ACCOUNT'),            // расчётный счёт ПРАЙМ
    ],

    // Исходящий прокси для carcade.com: адрес прода у них в бане.
    'carcade_proxy' => env('CARCADE_PROXY'),

    // Приём ставок после публикации и напоминание перед сроком этапа.
    'bids_window_days' => 3,
    'remind_before_minutes' => 60,
];
