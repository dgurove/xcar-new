<?php

namespace App\Http\Middleware;

use App\Http\Pwa\PwaController;
use App\Support\Icons;
use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;

/** Поверхность по хосту. На CRM, стоянке и в гараже отвечают только их маршруты и общие пути: вход, кабинет, чаты, live, PWA, редирект со старого /admin. */
class ResolveSurface
{
    private const SHARED = ['login', 'logout', 'lock', 'register', 'password', 'passkey', 'up', 'media', 'live', 'chats', 'account', 'manifest.webmanifest', 'offline', 'push', 'sw.js', 'icons.svg', 'deals', 'admin', 'mail', 'files'];

    /** Что открыто на чужом хосте тому, кому туда нельзя: выйти и служебное. */
    private const ANYONE = ['login', 'logout', 'password', 'passkey', 'up', 'manifest.webmanifest', 'offline', 'sw.js', 'icons.svg', 'admin'];

    public function handle(Request $request, Closure $next)
    {
        $surface = Surface::fromHost($request->getHost());
        app()->instance(Surface::class, $surface);
        // Страницы в браузере — значки ссылкой на спрайт `/icons.svg` (21.10.2026); письма и PDF без запроса — путём внутри.
        Icons::$sprite = true;

        if ($surface !== Surface::Site) {
            $first = explode('/', $request->path())[0];
            $own = $request->route()?->getDomain() === $surface->host();
            abort_unless($own || in_array($first, self::SHARED, true), 404);

            $user = $request->user();

            // Чужой вошедший упирается в стену без шапки и разделов; гостя `auth` уводит на /login этого хоста.
            $allowed = $surface->opensFor($user);
            // Управляющего из CRM и модератора с парковки — сразу к себе, а не в стену (если этот хост ему закрыт).
            $home = Surface::onlyFor($user);
            if ($home && $home !== $surface && ! $allowed && ! in_array($first, self::ANYONE, true) && ! $request->expectsJson()) {
                return redirect()->away($home->url());
            }
            if ($user && ! $allowed && ! in_array($first, self::ANYONE, true)) {
                abort_if($request->expectsJson(), 403);

                return response()->view('auth.foreign', ['user' => $user, 'surface' => $surface], 403)
                    ->header('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        $response = $next($request);
        // CRM и стоянка — не для поисковиков; для сайта решается отдельно, когда всё завершим.
        // Исключение — лендинг парковки для гостя: его ищут страховые.
        $landing = $surface === Surface::Park && $request->path() === '/' && ! $request->user();
        if ($surface !== Surface::Site && ! $landing && method_exists($response, 'header')) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }
        // Сборка страницы — воркеру: экран другой сборки из кэша он выбрасывает (sw.js, петля перезагрузок 10.10.2026).
        if (method_exists($response, 'header') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->header('X-Build', PwaController::build());
            // Принудительный сброс (11.10.2026, владелец: «у начальника не отображаются изменения»): один раз на метку —
            // браузер выбрасывает свой HTTP-кэш; кэши воркера стирает он сам по той же метке. «storage» не шлём: он снял бы
            // подписку на пуши, черновики и память вида.
            if ($request->cookie('xcar-reset') !== PwaController::RESET) {
                $response->header('Clear-Site-Data', '"cache"');
                $response->headers->setCookie(cookie()->forever('xcar-reset', PwaController::RESET, '/', config('session.domain'), null, false));
            }
        }

        return $response;
    }
}
