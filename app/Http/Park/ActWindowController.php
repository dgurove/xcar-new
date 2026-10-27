<?php

namespace App\Http\Park;

use App\Billing\Ledger;
use App\Park\Actions\MarkReportSent;
use App\Park\Pass;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use Illuminate\Http\Request;

/**
 * Окно действия (12.10.2026, владелец: «приём должен быть в отдельном окне, а не в интерфейсе карточки, выдача тоже —
 * разграничить машину и действие»): `x-park.act-window` — одна шторка на страницу с фреймом `act-frame`, шаги — эти
 * страницы. Приём (парковка, когда, фото) → «Принять и сформировать письмо» → «Письмо вендору» (`x-park.report-form`)
 * в том же окне, «Принять без письма» — окно закрывается, задача «Отправить акт приёма» ждёт и открывает окно сразу на
 * письме. Выдача — так же, письмо о выдаче — окном писем. `done` закрывает окно и освежает карточку или страницу
 * (`StreamActions['act-done']`); `advance` — из «Заявок»: карточка к следующей заявке.
 */
class ActWindowController
{
    public function show(Request $request, Vehicle $vehicle, string $step)
    {
        $advance = $request->boolean('advance');
        $url = fn (string $s, array $q = []) => "/cars/{$vehicle->id}/window/{$s}".(($q += $advance ? ['advance' => 1] : []) ? '?'.http_build_query($q) : '');
        if ($step === 'done') {
            if ($advance) {
                session()->flash('detail-advance', true);
            }
            $letters = (string) $request->query('letters');

            return view('park.act.done', ['letters' => preg_match('#^/(mail|cars)/#', $letters) ? $letters : null]);
        }

        $vehicle->load(['vendor', 'yard', 'media', 'requests']);
        $user = $request->user();
        $common = ['vehicle' => $vehicle, 'advance' => $advance, 'self' => $url($step, array_filter(['pass' => $request->query('pass')])), 'canManage' => $user->canManagePark(),
            // Приёмщик (27.10.2026): принимает и выдаёт, деньги — только наличные с покупателя (`User::parkHandles|parkMoney`).
            'handles' => $user->parkHandles(), 'money' => $user->parkMoney()];

        if ($step === 'report') {
            if (! $vehicle->accepted_at || MarkReportSent::done($vehicle)) {
                return redirect($url('done'));
            }

            return view('park.act.report', $common);
        }

        if ($step === 'intake') {
            if (! $vehicle->state->isBefore()) {
                return redirect($url($vehicle->accepted_at && self::reportDue($vehicle) ? 'report' : 'done'));
            }
            $yards = Yard::where('is_active', true)->orderBy('name')->get();

            return view('park.act.intake', $common + [
                'req' => $vehicle->openRequest(RequestType::Intake),
                'yards' => $yards->pluck('name', 'id'),
                'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
                // Марку и модель приём требует (`VehicleFields`): письмо их не дало — поля тут же.
                // Приёмщику тоже: без марки и модели ТС не принять.
                'gaps' => $user->parkHandles() && (! $vehicle->brand_id || ! $vehicle->model_id),
                'letter' => self::billable($vehicle),
                'then' => $url('report'),
            ]);
        }

        // Выдача — только стоящей.
        if ($vehicle->state !== VehicleState::Stored) {
            return redirect($url('done'));
        }
        $qr = $vehicle->releasesByQr();

        return view('park.act.release', $common + [
            'req' => $vehicle->openRequest(RequestType::Release),
            'byQr' => $qr, 'pass' => $qr ? $vehicle->pass() : null,
            'scannedPass' => $qr ? Pass::codeFrom((string) $request->query('pass')) : null,
            'releaseDebt' => Ledger::releaseDebt($vehicle), 'cashDue' => Ledger::buyerUnbilled($vehicle), 'vendorDue' => Ledger::vendorUnbilled($vehicle),
            'debtBlocks' => ! ($vehicle->vendor?->release_without_payment ?? false),
            'letter' => self::billable($vehicle) && $vehicle->reportUrl('release', "/cars/{$vehicle->id}"),
        ]);
    }

    /** Вендор, которому шлём отчёты о приёме и выдаче (страховые, лизинг; не собственный транспорт). */
    public static function billable(Vehicle $vehicle): bool
    {
        return $vehicle->vendor?->kind->billable() ?? false;
    }

    /** Отчёт о приёме ещё не ушёл — задача «Отправить акт приёма». */
    public static function reportDue(Vehicle $vehicle): bool
    {
        return $vehicle->accepted_at && self::billable($vehicle) && ! MarkReportSent::done($vehicle);
    }
}
