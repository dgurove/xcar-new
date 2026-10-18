<?php

namespace App\Http\Park;

use App\Logistics\Actions\OpenMove;
use App\Park\Vehicle;
use Illuminate\Http\Request;

/** «В логистику» из дела ТС (10.10.2026): перевод на другую парковку или по адресу — задачей logistics.xcar.ru. */
class LogisticsController
{
    public function store(Request $request, Vehicle $vehicle, OpenMove $open)
    {
        $t = $open($vehicle, $request->validate(OpenMove::rules()), $request->user());

        return back()->with('toast', 'Логистика: '.($t->carrier() ?? 'ждёт исполнителя'));
    }
}
