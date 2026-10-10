<?php

namespace App\Http\Park;

use App\Logistics\Actions\SaveTransport;
use App\Logistics\Actions\SendToLogistics;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** «В логистику» из дела ТС (10.10.2026): перевод в другой город, перевозка с парковки — перевозкой logistics.xcar.ru. */
class LogisticsController
{
    public function store(Request $request, Vehicle $vehicle, SendToLogistics $send)
    {
        if (! in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
            throw ValidationException::withMessages(['scope' => 'Везти можно ТС на парковке или ожидаемую']);
        }
        $data = $request->validate(SaveTransport::rules(), SaveTransport::messages());
        $t = $send($vehicle, $data, $request->user());

        return back()->with('toast', 'Логистика: '.($t->executorName() ?? 'ждёт логиста'));
    }
}
