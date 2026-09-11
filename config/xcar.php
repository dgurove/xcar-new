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

    // Приём ставок после публикации и напоминание перед сроком этапа.
    'bids_window_days' => 3,
    'remind_before_minutes' => 60,
];
