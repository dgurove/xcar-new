<?php

return [
    // Хосты. Стоянка живёт на своём поддомене того же приложения.
    'park_host' => env('PARK_HOST', 'park.localhost'),

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

    // Исходящий прокси для carcade.com: адрес прода у них в бане.
    'carcade_proxy' => env('CARCADE_PROXY'),

    // Приём ставок после публикации и напоминание перед сроком этапа.
    'bids_window_days' => 3,
    'remind_before_minutes' => 60,
];
