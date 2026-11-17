<?php

namespace App\Http\Admin;

use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Корень CRM — сразу предложения (17.11.2026: космос с конвейером не прижился, `archive/space`). Старые ссылки на список
 * (`/?preset=…&peek=…` в Telegram, пушах, закладках) — на тот же адрес под `/offers`. Маршрут вне `auth`: гостя на вход
 * отправит уже `/offers`, и после входа он вернётся в список. Не 301 — постоянный редирект браузер запоминает навсегда.
 */
final class HomeController
{
    public function __invoke(Request $request)
    {
        $query = $request->getQueryString();

        return redirect(Surface::Crm->home().($query !== null ? '?'.$query : ''));
    }
}
