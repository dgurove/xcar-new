<?php

namespace App\Http\Pwa;

use App\Http\Middleware\ParkHost;
use Illuminate\Http\Request;

/** Манифест — свой на каждый хост: имя, стартовая страница, цвет. */
class PwaController
{
    public function manifest(Request $request)
    {
        $park = ParkHost::isPark($request);

        return response()->json([
            'name' => $park ? 'XCar Стоянка' : 'XCar',
            'short_name' => $park ? 'Стоянка' : 'XCar',
            'description' => $park ? 'Заявки, машины, стоянки' : 'Предложения, сделки, закупки',
            'start_url' => $park ? '/zayavki' : '/',
            'id' => $park ? '/zayavki' : '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#121212',
            'theme_color' => '#121212',
            'lang' => 'ru',
            'icons' => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => $park ? [
                ['name' => 'Заявки', 'url' => '/zayavki'], ['name' => 'Машины', 'url' => '/mashiny'],
            ] : [
                ['name' => 'Предложения', 'url' => '/'], ['name' => 'Сделки', 'url' => '/lk/sdelki'], ['name' => 'Уведомления', 'url' => '/lk/uvedomleniya'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function offline()
    {
        return view('site.offline');
    }
}
