<?php

namespace App\Http\Pwa;

use App\Support\Icons;
use App\Support\Surface;
use App\Users\Access\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Манифест — свой на каждую поверхность: имя, стартовая страница, ярлыки, иконки. */
class PwaController
{
    public function manifest()
    {
        $surface = Surface::current();
        $dir = '/pwa/'.$surface->value;

        [$description, $shortcuts] = match ($surface) {
            // Манифест один на всех и без cookie (браузер берёт его без входа): ярлыки — только то, что есть у каждой роли.
            // Иконки ярлыков делает scripts/icons.mjs по тому же списку (SHORTCUT_ICONS).
            Surface::Site => ['Предложения, сделки, закупки, гараж', [
                ['Предложения', '/offers', 'cat-car'], ['Уведомления', '/account/notifications', 'bell'],
            ]],
            Surface::Crm => ['Предложения, галерея, работа, закупки', [
                ['Предложения', '/', 'cat-car'], ['Галерея', '/gallery', 'photo'], ['Работа', '/work', 'deal'], ['Закупки', '/purchases', 'cart'],
            ]],
            Surface::Park => ['Заявки, ТС, парковки', [
                ['Заявки', '/requests', 'flag'], ['ТС', '/cars', 'cat-car'],
            ]],
            Surface::Logistics => ['Перевозки ТС', [
                ['Перевозки', '/transports', 'cat-car'],
            ]],
        };

        // Иконка — чёрный квадрат во весь холст: стекло на iOS 26 и маску на Android кладёт система,
        // поэтому фон запуска и полосы — тот же чёрный, чтобы квадрат не читался на сером.
        return response()->json([
            'name' => $surface->label(),
            'short_name' => $surface->short(),
            'description' => $description,
            // Старт — сразу в список; id прежний, иначе установленное приложение стало бы другим.
            'start_url' => $surface->home().'?app=1',
            'id' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone'],
            'orientation' => 'portrait',
            // Цвет светлой полосы (--color-bar, $bar в x-ui.layout; сверяет blade-lint): чёрный давал на Android чёрный
            // экран запуска и белую вспышку следом.
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
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

    /**
     * Сторож петли перезагрузок (resources/js/reload-guard.js) прислал, что гоняло окно: четыре полные загрузки за 30 с,
     * у каждой — адрес, причина (turbo:…, sw:reload), был ли воркер. Только в лог: по нему ищем, откуда петля.
     */
    public function loop(Request $request)
    {
        $loads = array_slice((array) $request->input('loads'), 0, 10);
        Log::warning('pwa: петля перезагрузок', [
            'host' => $request->getHost(),
            'user' => $request->user()?->id,
            'standalone' => $request->boolean('standalone'),
            'ua' => mb_substr((string) $request->userAgent(), 0, 200),
            'loads' => array_map(fn ($l) => is_array($l) ? array_intersect_key($l, array_flip(['at', 'url', 'why', 'sw'])) : null, $loads),
        ]);

        return response()->noContent();
    }

    /** Воркер с версией из хэша сборки: новая выкладка — новый кэш, без правки руками. */
    /**
     * Спрайт значков (`Support\Icons`, 21.10.2026): один файл на все страницы — значок в разметке только ссылка `<use>`.
     * Адрес с версией (`Icons::url`), поэтому навсегда в кэше.
     */
    public function icons()
    {
        return response(Icons::sprite(standalone: true), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function worker()
    {
        // Локально воркер снимает себя: однопоточный `php -S` рвёт его запросы после пересборки, и страница остаётся без стилей.
        if (app()->isLocal()) {
            return response("self.addEventListener('install', () => self.skipWaiting());\nself.addEventListener('activate', (e) => e.waitUntil(caches.keys().then((k) => Promise.all(k.map((n) => caches.delete(n)))).then(() => self.registration.unregister())));\n",
                200, ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
        }
        $version = self::build();
        // Тумблер замка менеджеров — в версии: включили, и воркер обновится сам, стерев экраны, накопленные до замка.
        $version .= Lock::enabled() ? 'L' : '';
        $js = str_replace(['__VERSION__', '__RESET__'], [$version, self::RESET], file_get_contents(resource_path('sw.js')));

        return response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    /**
     * Метка принудительного сброса (sw.js): сменили — у всех стираются кэши воркера и разово HTTP-кэш браузера
     * (`Clear-Site-Data: "cache"`, ResolveSurface по cookie `xcar-reset`). Вход, черновики и подписка пушей остаются.
     */
    public const RESET = '2026-10-11-1';

    /**
     * Метка сборки: хэш манифеста Vite. Ею помечен воркер и каждая страница (`X-Build`, `ResolveSurface`) — воркер
     * выбрасывает экран прошлой сборки из кэша, не дожидаясь своей смены (иначе — петля перезагрузок, см. sw.js).
     * Сборка меняется только с выкладкой (новый контейнер) — память процесса её не переживёт.
     */
    public static function build(): string
    {
        static $build;

        return $build ??= is_file($manifest = public_path('build/manifest.json')) ? 'b'.substr(md5_file($manifest), 0, 8) : 'dev';
    }
}
