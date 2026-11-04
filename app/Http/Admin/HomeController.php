<?php

namespace App\Http\Admin;

use Illuminate\Http\Request;

/**
 * Главная CRM — космос, фон будущей схемы (04.11.2026, владелец). Предложения переехали на `/offers`, работа начинается
 * с них (`Surface::home`), сюда ведёт только логотип. Маршрут вне `auth`, как `/` сайта и парковки: гость уходит на вход
 * без «вернуться сюда» — после входа он в предложениях, а не в космосе. Старые ссылки на список (`/?preset=…&peek=…` в
 * Telegram, пушах, закладках) — 301 на тот же адрес под `/offers`, до входа: адрес для возврата запомнит уже список.
 */
final class HomeController
{
    public function __invoke(Request $request)
    {
        if (($query = $request->getQueryString()) !== null) {
            return redirect('/offers?'.$query, 301);
        }
        if (! $request->user()) {
            return redirect('/login');
        }
        abort_unless($request->user()->isStaff(), 404);

        return view('admin.space');
    }
}
