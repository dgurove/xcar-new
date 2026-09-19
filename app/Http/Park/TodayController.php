<?php

namespace App\Http\Park;

use App\Park\Today;
use Illuminate\Http\Request;

/**
 * Рабочий стол стоянки: что просрочено, кому позвонить, что забрать, принять,
 * выдать — секциями на выбранный день; пустая секция не рисуется. Всё в адресе:
 * `den` — сегодня / завтра / неделя / все, `moi` — только мои.
 */
class TodayController
{
    public function index(Request $request)
    {
        $day = array_key_exists($request->query('day', ''), Today::DAYS) ? $request->query('day') : 'today';
        $mine = $request->boolean('mine');

        return view('park.today', ['day' => $day, 'days' => Today::DAYS, 'mine' => $mine] + Today::build($request->user(), $day, $mine));
    }
}
