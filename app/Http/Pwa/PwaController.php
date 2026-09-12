<?php

namespace App\Http\Pwa;

use App\Support\Surface;

/** Манифест — свой на каждую поверхность: имя, стартовая страница, ярлыки, иконки. */
class PwaController
{
    public function manifest()
    {
        $surface = Surface::current();
        $dir = '/pwa/'.$surface->value;

        [$description, $start, $shortcuts] = match ($surface) {
            Surface::Site => ['Предложения, сделки, закупки', '/', [
                ['name' => 'Предложения', 'url' => '/'], ['name' => 'Сделки', 'url' => '/lk/sdelki'], ['name' => 'Уведомления', 'url' => '/lk/uvedomleniya'],
            ]],
            Surface::Crm => ['Предложения, галерея, работа, закупки', '/', [
                ['name' => 'Предложения', 'url' => '/'], ['name' => 'Галерея', 'url' => '/galereya'], ['name' => 'Работа', 'url' => '/rabota'], ['name' => 'Закупки', 'url' => '/zakupki'],
            ]],
            Surface::Park => ['Заявки, машины, стоянки', '/zayavki', [
                ['name' => 'Заявки', 'url' => '/zayavki'], ['name' => 'Машины', 'url' => '/mashiny'],
            ]],
        };

        return response()->json([
            'name' => $surface->label(),
            'short_name' => $surface->short(),
            'description' => $description,
            'start_url' => $start,
            'id' => $start,
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#121212',
            'theme_color' => '#121212',
            'lang' => 'ru',
            'icons' => [
                ['src' => "$dir/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => "$dir/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => "$dir/icon-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => $shortcuts,
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function offline()
    {
        return view('site.offline');
    }
}
