<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;

/**
 * Стена вокруг сайта: гостю — вход и регистрация, зарегистрированному без
 * допуска — страница ожидания, отклонённому — «мы вас не узнали». Открыты
 * только обращение (/contacts), юридические страницы и служебное — они
 * не под этой прослойкой. CRM и стоянка чужих отбивают сами (ResolveSurface).
 */
class SiteWall
{
    public function handle(Request $request, Closure $next)
    {
        if (Surface::current() !== Surface::Site) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user) {
            return $request->expectsJson() ? abort(401) : redirect()->guest('/login');
        }
        // Управляющему парковкой и модератору сайта нет: их место — park.xcar и CRM, туда и уводим.
        if ($home = Surface::onlyFor($user)) {
            return $request->expectsJson() ? abort(403) : redirect()->away($home->url());
        }
        // Покупатель без менеджера — только свой гараж (21.10.2026): предложения, галерея и интерес ему пусты.
        if ($user->isApproved() && $user->onlyGarage() && $request->isMethod('GET') && $request->is('/', 'offers', 'gallery', 'account/interests')) {
            return redirect('/garage');
        }
        if ($user->isApproved()) {
            return $next($request);
        }

        return response()->view($user->isRejected() ? 'auth.rejected' : 'auth.waiting', ['user' => $user], 403);
    }
}
