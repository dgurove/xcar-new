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

        [$description, $shortcuts] = match ($surface) {
            Surface::Site => ['Предложения, сделки, закупки', [
                ['Предложения', '/', 'car'], ['Сделки', '/account/deals', 'deal'], ['Уведомления', '/account/notifications', 'bell'],
            ]],
            Surface::Crm => ['Предложения, галерея, работа, закупки', [
                ['Предложения', '/', 'car'], ['Галерея', '/gallery', 'photo'], ['Работа', '/work', 'deal'], ['Закупки', '/purchases', 'cart'],
            ]],
            Surface::Park => ['Заявки, ТС, стоянки', [
                ['Заявки', '/', 'flag'], ['ТС', '/cars', 'car'],
            ]],
        };

        // Иконка — чёрный квадрат во весь холст: стекло на iOS 26 и маску на Android кладёт система,
        // поэтому фон запуска и полосы — тот же чёрный, чтобы квадрат не читался на сером.
        return response()->json([
            'name' => $surface->label(),
            'short_name' => $surface->short(),
            'description' => $description,
            'start_url' => '/?app=1',
            'id' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone'],
            'orientation' => 'portrait',
            'background_color' => '#000000',
            'theme_color' => '#000000',
            'lang' => 'ru',
            'categories' => ['business'],
            'launch_handler' => ['client_mode' => 'navigate-existing'],
            'icons' => [
                ['src' => "$dir/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "$dir/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "$dir/icon-1024.png", 'sizes' => '1024x1024', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "$dir/icon-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => "$dir/icon-mono-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'monochrome'],
            ],
            'shortcuts' => array_map(fn ($s) => [
                'name' => $s[0], 'url' => $s[1],
                'icons' => [['src' => "$dir/shortcut-$s[2].png", 'sizes' => '96x96', 'type' => 'image/png']],
            ], $shortcuts),
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function offline()
    {
        return view('site.offline');
    }

    /** Воркер с версией из хэша сборки: новая выкладка — новый кэш, без правки руками. */
    public function worker()
    {
        $manifest = public_path('build/manifest.json');
        $version = is_file($manifest) ? 'b'.substr(md5_file($manifest), 0, 8) : 'dev';
        $js = str_replace('__VERSION__', $version, file_get_contents(resource_path('sw.js')));

        return response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}
