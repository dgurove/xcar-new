<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Events\GarageChanged;
use App\Garage\Payer;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Offers\Actions\Give;
use App\Offers\Deal;
use App\Offers\Giving;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Передать… → В сделку» (19.11.2026, владелец: «из гаража кому-то в сделку или в сделку этому же менеджеру»): машина
 * уходит из гаража в обычную сделку менеджера ценой, как «Отдать → В сделку». Строка гаража снимается (с ней — наши
 * расходы: они уже в закупочной), идущая гаражная сделка отменяется в `Give` как прежняя, закрытая остаётся историей.
 * Закупочная новой сделки — закупочная гаражной плюс наши расходы (владелец), в шторке правится. Расходы менеджера у
 * обычной сделки некуда деть — тогда «В сделку» нельзя: убрать их или передать в гараж.
 */
final class GarageToDeal
{
    public function __construct(private Give $give) {}

    /** Почему в сделку нельзя; null — можно (`CarView`, шторка «Передать»). */
    public static function blocked(Car $car): ?string
    {
        return HandOverGarage::blocked($car)
            ?? ($car->costs()->where('payer', Payer::Manager)->exists() ? 'У ТС есть расходы менеджера: сначала уберите их или передайте в гараж' : null);
    }

    /** Закупочная новой сделки: закупочная гаражной плюс наши расходы (ремонт, вывоз). */
    public static function cost(Car $car): ?int
    {
        $base = $car->deal?->cost ?? $car->offer?->floor_price;

        return $base === null ? null : (int) round($base + $car->spent(Payer::Xcar));
    }

    public function __invoke(Giving $g, User $by): Deal
    {
        return DB::transaction(function () use ($g, $by) {
            $car = Car::where('offer_id', $g->offer->id)->lockForUpdate()->with(['deal', 'manager', 'offer'])->firstOrFail();
            if ($why = self::blocked($car)) {
                throw ValidationException::withMessages(['give' => $why]);
            }
            if ($g->garage || ! $g->manager) {
                throw ValidationException::withMessages(['manager_id' => 'Кому отдать']);
            }
            $from = $car->manager;
            $car->log($by, array_filter(['do' => 'to_deal', 'to' => $g->manager->name, 'from' => $from?->name], fn ($v) => $v !== null));
            // Строка гаража — до `Give`: иначе `CancelDeal` отказал бы («ТС уже у менеджера»), а `Garage → Sold` — «ТС в гараже».
            $car->delete();
            GarageChanged::dispatch($car);
            // Везли к прежнему держателю, а сделка у другого — куда везти, решит вывоз новой сделки.
            $open = $from && ! $from->is($g->manager) ? Transport::openFor($g->offer) : null;
            if ($open && ! $open->taken() && $open->destination === Point::Keeper) {
                $open->update(['destination' => null]);
            }

            return ($this->give)($g, $by);
        });
    }
}
