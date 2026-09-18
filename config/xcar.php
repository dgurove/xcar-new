<?php

return [
    // Хосты. CRM и стоянка — поддомены того же приложения; сайт — хост app.url.
    'crm_host' => env('CRM_HOST', 'crm.localhost'),
    'park_host' => env('PARK_HOST', 'park.localhost'),

    // Наше юрлицо — в актах стоянки. Реквизиты для счетов появятся с деньгами.
    'company' => ['name' => 'ООО «ПРАЙМ»', 'inn' => '5007110932', 'director' => 'Кузнецов А. В.'],

    // Стоянка: после скольких дней хранения без движения ТС попадает в «стоят долго».
    'park_idle_days' => 30,

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

    // Исходящий прокси для carcade.com: адрес прода у них в бане.
    'carcade_proxy' => env('CARCADE_PROXY'),

    // Приём ставок после публикации и напоминание перед сроком этапа.
    'bids_window_days' => 3,
    'remind_before_minutes' => 60,
];
