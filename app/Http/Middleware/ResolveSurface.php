<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use App\Users\Section;
use Closure;
use Illuminate\Http\Request;

/** Поверхность по хосту. На CRM и стоянке отвечают только их маршруты и общие пути: вход, кабинет, чаты, live, PWA, редирект со старого /admin. */
class ResolveSurface
{
    private const SHARED = ['vhod', 'vyhod', 'registraciya', 'parol', 'passkey', 'up', 'media', 'live', 'chaty', 'lk', 'manifest.webmanifest', 'offline', 'push', 'sw.js', 'sdelki', 'admin'];

    /** Что открыто на CRM и стоянке тому, кому туда нельзя: выйти и служебное. */
    private const ANYONE = ['vhod', 'vyhod', 'parol', 'passkey', 'up', 'manifest.webmanifest', 'offline', 'sw.js', 'admin'];

    public function handle(Request $request, Closure $next)
    {
        $surface = Surface::fromHost($request->getHost());
        app()->instance(Surface::class, $surface);

        if ($surface !== Surface::Site) {
            $first = explode('/', $request->path())[0];
            $own = $request->route()?->getDomain() === $surface->host();
            abort_unless($own || in_array($first, self::SHARED, true), 404);

            // Чужому здесь делать нечего: 404, а не 403, чтобы не подсказывать, что есть.
            $user = $request->user();
            $allowed = $surface === Surface::Crm ? $user?->isStaff() : $user?->canAccess(Section::Park);
            abort_if($user && ! $allowed && ! in_array($first, self::ANYONE, true), 404);
        }

        $response = $next($request);
        // CRM и стоянка — не для поисковиков; для сайта решается отдельно, когда всё завершим.
        if ($surface !== Surface::Site && method_exists($response, 'header')) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
