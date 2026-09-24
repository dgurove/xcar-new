<?php

namespace App\Http\Park;

use App\Park\Actions\ConfirmBuyer;
use App\Park\Actions\SendPickupLink;
use App\Park\Pass;
use App\Park\Vehicle;
use Illuminate\Http\Request;

/**
 * Выдача по QR, сторона парковки: проверка отсканированного кода при выдаче, «Страховая подтвердила»,
 * «Не покупатель», повторная отправка ссылки страховой. Сама выдача — RequestController::release.
 */
class PassController
{
    /** Что за код отсканировали: подходит ли для этой ТС и подтверждён ли покупатель. Ответ — плашка сканера. */
    public function check(Request $request, Vehicle $vehicle)
    {
        $pass = Pass::byCode((string) $request->input('code'));
        $pass?->load(['vehicle.yard', 'vehicle.brand', 'vehicle.model']);
        $when = fn (Pass $p) => 'Заберёт '.$p->pickup_on->translatedFormat('j F').'. Сверьте с паспортом';

        return response()->json(match (true) {
            ! $pass => ['status' => 'unknown', 'title' => 'Это не пропуск XCar', 'text' => 'Попросите показать QR-код из письма'],
            $pass->vehicle_id !== $vehicle->id => ['status' => 'other', 'title' => 'QR для другого ТС',
                'text' => $pass->vehicle->titleWithYear().($pass->vehicle->yard ? ', парковка '.$pass->vehicle->yard->name : ''), 'url' => '/cars/'.$pass->vehicle_id],
            (bool) $pass->used_at => ['status' => 'used', 'title' => 'По этому QR ТС уже выдано', 'text' => $pass->used_at->translatedFormat('j F, H:i')],
            (bool) $pass->revoked_at => ['status' => 'revoked', 'title' => 'Пропуск погашен', 'text' => $pass->revoke_reason ?: 'Покупатель сменился'],
            ! $pass->isConfirmed() => ['status' => 'unconfirmed', 'code' => $pass->code, 'name' => $pass->name, 'title' => 'Страховая ещё не подтвердила покупателя',
                'text' => $pass->name.'. '.$when($pass)],
            default => ['status' => 'ok', 'code' => $pass->code, 'name' => $pass->name, 'title' => 'QR подтверждён: '.$pass->name, 'text' => $when($pass)],
        });
    }

    public function confirm(Request $request, Vehicle $vehicle, ConfirmBuyer $confirm)
    {
        $pass = $vehicle->pass();
        abort_unless($pass?->isLive(), 404);
        $confirm($pass, $request->user());

        return back()->with('toast', 'Покупатель подтверждён');
    }

    public function reject(Request $request, Vehicle $vehicle, ConfirmBuyer $confirm)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $pass = $vehicle->pass();
        abort_unless($pass?->isLive(), 404);
        $confirm->reject($pass, $request->user(), $data['reason']);

        return back()->with('toast', 'Пропуск погашен');
    }

    public function link(Request $request, Vehicle $vehicle, SendPickupLink $send)
    {
        return back()->with('toast', $send->send($vehicle, null, $request->user()) ? 'Ссылка отправлена страховой' : 'Не нашли, кому писать: нет письма страховой и адреса у вендора');
    }
}
