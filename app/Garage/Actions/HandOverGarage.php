<?php

namespace App\Garage\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Garage\GaragePayer;
use App\Notifications\GarageHandedNotice;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Передать…» гаражную машину другому держателю (19.11.2026, начальник: Chery 2611013407 взяли «под себя», её надо в гараж
 * Ананьеву; «Отдать…» есть, только пока машина едет, и отменяет сделку): держатель меняется в той же сделке и той же строке
 * гаража — этап, вывоз, расходы и история остаются. Кому — менеджер, покупатель с гаражом или «Себе» (снова под себя).
 * Поставщику по-прежнему платим мы (у Совкомбанка — ПРАЙМ, покупатель платит ПРАЙМ по счёту): смена «мы ↔ менеджер» меняет
 * маршрут и счета — это «Отдать…», пока ТС едет. Покупателю — его цена (`buyer_price`) и счёт ПРАЙМ на неё
 * (`SyncDealInvoices`); прежнему покупателю неоплаченный счёт аннулируется, оплаченный — передавать нельзя.
 */
final class HandOverGarage
{
    public function __construct(private SyncDealInvoices $sync, private VoidInvoice $void) {}

    /** Почему передать нельзя; null — можно. Им же решает «⋯» и плашка держателя (`CarView`). */
    public static function blocked(Car $car): ?string
    {
        $deal = $car->deal;

        return match (true) {
            ! $deal || ! $deal->isActive() || ! $deal->isGarage() => 'Передать можно ТС в гараже с идущей сделкой',
            $deal->garage_payer !== GaragePayer::Us => 'Поставщику платит менеджер: передать другому — «Отдать…», пока ТС едет',
            $car->sold_at !== null || in_array($car->state, [CarState::Sold, CarState::Settled], true) => 'ТС уже продана',
            $car->forBuyer() && $car->buyerHasPaid() => 'Покупатель уже платил по счёту: сначала верните оплату',
            default => null,
        };
    }

    public function __invoke(Car $car, ?User $to, ?int $buyerPrice, User $by): Car
    {
        return DB::transaction(function () use ($car, $to, $buyerPrice, $by) {
            $car = Car::whereKey($car->id)->lockForUpdate()->with(['deal.offer.vendor', 'manager', 'offer'])->firstOrFail();
            $deal = Deal::whereKey($car->deal_id)->lockForUpdate()->first();
            $car->setRelation('deal', $deal);
            if ($why = self::blocked($car)) {
                throw ValidationException::withMessages(['holder' => $why]);
            }
            $this->check($car, $to, $buyerPrice);

            $from = $car->manager;
            // Счёт прежнему покупателю с гаражом (не оплачен — проверил `blocked`) — аннулируется: ТС больше не его.
            if ($car->forBuyer() && ($old = $car->holderInvoice())) {
                ($this->void)($old, $by, 'ТС передана другому держателю');
            }
            $deal->update(['buyer_id' => $to?->id, 'bid_id' => null]);
            $car->update(['manager_id' => $to?->id, 'buyer_price' => $to?->holdsAsBuyer() ? $buyerPrice : null]);
            $car->log($by, array_filter(['do' => 'holder', 'to' => $to?->name, 'from' => $from?->name,
                'price' => $to?->holdsAsBuyer() ? $buyerPrice : null], fn ($v) => $v !== null));
            $car = $car->fresh(['deal.offer', 'manager']);
            // Счёт ПРАЙМ новому покупателю на его цену — сразу, как при «Отдать» (`Give`).
            ($this->sync)($car->deal->fresh(), $by);
            GarageChanged::dispatch($car);

            $offer = $car->offer;
            DB::afterCommit(function () use ($to, $from, $offer) {
                $to?->notify(new GarageHandedNotice($offer, true));
                if ($from && ! $from->is($to)) {
                    $from->notify(new GarageHandedNotice($offer, false));
                }
            });

            return $car;
        });
    }

    private function check(Car $car, ?User $to, ?int $buyerPrice): void
    {
        $offer = $car->offer;
        $error = match (true) {
            $to === null && $car->manager_id === null => 'ТС и так взяли под себя',
            $to !== null && $to->id === $car->manager_id => 'ТС и так у него',
            $to === null => null,
            $to->holdsAsBuyer() && ! $to->garageBuyer() => 'Гараж покупателю не включён',
            $to->holdsAsBuyer() && ! $offer->garageToBuyer() => 'У этого поставщика покупателю в гараж не отдать',
            $to->holdsAsBuyer() => null,
            ! $to->isManager() => 'В гараж ТС берёт менеджер или покупатель с гаражом',
            ! $to->canGarage() => 'Гараж менеджеру не включён',
            // У Совкомбанка менеджер в гараже платит сам (ПРАЙМ по счёту, `Offer::garagePayerFixed`) — это другая ветка маршрута.
            $offer->garagePayerFixed() === GaragePayer::Manager => 'У этого поставщика менеджер в гараже платит сам: передать можно покупателю с гаражом или себе',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['manager_id' => $error]);
        }
        if ($to?->holdsAsBuyer() && (! $buyerPrice || $buyerPrice < 1)) {
            throw ValidationException::withMessages(['buyer_price' => 'Цена для покупателя']);
        }
    }
}
