<?php

namespace App\Http\Admin;

use App\Live\Stream;
use App\Space\Conveyor;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Главная CRM — космос с конвейером (04.11.2026, владелец; `App\Space\Conveyor`). Предложения переехали на `/offers`, работа начинается
 * с них (`Surface::home`), сюда ведёт только логотип. Маршрут вне `auth`, как `/` сайта и парковки: гость уходит на вход
 * без «вернуться сюда» — после входа он в предложениях, а не в космосе. Старые ссылки на список (`/?preset=…&peek=…` в
 * Telegram, пушах, закладках) — на тот же адрес под `/offers`, до входа: адрес для возврата запомнит уже список. Не 301:
 * постоянный редирект браузер запоминает навсегда, и свои параметры у главной (схема) потом не заработали бы.
 * Космос — только изнутри CRM, логотипом (05.11.2026: начальник открыл CRM закладкой на корень и попал на «Тест»): открыли
 * CRM снаружи — адрес руками, закладка, новая вкладка, ссылка с другого сайта — сразу предложения. Изнутри — переход Turbo
 * или клик того же сайта (`Sec-Fetch-Site: same-origin`) либо Referer с хоста CRM (обновление страницы его сохраняет).
 */
final class HomeController
{
    public function __invoke(Request $request)
    {
        if (($query = $request->getQueryString()) !== null) {
            return redirect('/offers?'.$query);
        }
        if (! $request->user()) {
            return redirect('/login');
        }
        abort_unless($request->user()->isStaff(), 404);
        if (! self::fromInside($request)) {
            return redirect(Surface::Crm->home());
        }

        return view('admin.space', ['nodes' => Conveyor::view()]);
    }

    /** Живые числа конвейера: событие сотрудникам — `space_controller` перечитывает кусок `#conveyor` морфом. */
    public function conveyor()
    {
        return Stream::view('admin.space.stream', ['nodes' => Conveyor::view()]);
    }

    private static function fromInside(Request $request): bool
    {
        return $request->header('Sec-Fetch-Site') === 'same-origin'
            || parse_url((string) $request->header('Referer'), PHP_URL_HOST) === $request->getHost();
    }
}
