<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use App\Users\Section;
use Closure;
use Illuminate\Http\Request;

/** Поверхность по хосту. На CRM и стоянке отвечают только их маршруты и общие пути: вход, кабинет, чаты, live, PWA, редирект со старого /admin. */
class ResolveSurface
{
    private const SHARED = ['login', 'logout', 'register', 'password', 'passkey', 'up', 'media', 'live', 'chats', 'account', 'manifest.webmanifest', 'offline', 'push', 'sw.js', 'deals', 'admin', 'mail', 'files'];

    /** Что открыто на CRM и стоянке тому, кому туда нельзя: выйти и служебное. */
    private const ANYONE = ['login', 'logout', 'password', 'passkey', 'up', 'manifest.webmanifest', 'offline', 'sw.js', 'admin'];

    public function handle(Request $request, Closure $next)
    {
        $surface = Surface::fromHost($request->getHost());
        app()->instance(Surface::class, $surface);

        if ($surface !== Surface::Site) {
            $first = explode('/', $request->path())[0];
            $own = $request->route()?->getDomain() === $surface->host();
            abort_unless($own || in_array($first, self::SHARED, true), 404);

            $user = $request->user();
            // Технические работы: стоянка открыта только тем, кто в PARK_ONLY; вход и служебное — всем.
            $only = config('xcar.park_only');
            if ($surface === Surface::Park && $only && ! in_array($first, self::ANYONE, true) && ! in_array((string) $user?->phone, $only, true)) {
                abort_if($request->expectsJson(), 503);

                return response()->view('park.closed', [], 503)->header('X-Robots-Tag', 'noindex, nofollow');
            }

            // Чужой вошедший упирается в стену без шапки и разделов; гостя `auth` уводит на /login этого хоста.
            $allowed = $surface === Surface::Crm ? $user?->isStaff() : $user?->canAccess(Section::Park);
            if ($user && ! $allowed && ! in_array($first, self::ANYONE, true)) {
                abort_if($request->expectsJson(), 403);

                return response()->view('auth.foreign', ['user' => $user, 'surface' => $surface], 403)
                    ->header('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        $response = $next($request);
        // CRM и стоянка — не для поисковиков; для сайта решается отдельно, когда всё завершим.
        if ($surface !== Surface::Site && method_exists($response, 'header')) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
