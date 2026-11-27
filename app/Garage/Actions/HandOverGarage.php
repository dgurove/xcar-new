<?php

namespace App\Garage\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Garage\GaragePayer;
use App\Notifications\GarageHandedNotice;
use App\Offers\Actions\AskBuyer;
use App\Offers\Actions\Give;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Передать… → В гараж» другому держателю (19.11.2026, начальник: Chery 2611013407 взяли «под себя», её надо в гараж
 * Ананьеву; вечером владелец: «из гаража в гараж, из гаража кому-то в сделку или этому же — чтобы вариативность была»):
 * держатель меняется в той же сделке и той же строке гаража — этап, вывоз, расходы и история остаются. Кому — менеджер,
 * покупатель с гаражом или «Себе». Любой плательщик, и закрытая гаражная тоже, пока машину не продали и расчёт не выставлен.
 *
 * Кто платит поставщику: покупателю и «Себе» — мы; у Совкомбанка менеджер платит сам (ПРАЙМ по счёту, `Offer::garagePayerFixed`);
 * иначе — как было. Сменился плательщик у идущей сделки — сделка встаёт на свою ветку, как у «Отдать» (`Give::handOver`).
 * Расходы прежнего держателя переходят новому, что прежний уже платил, — история сделки, разбирают руками (владелец:
 * «забей»): неоплаченные счета прежнему гаснут и встают новому (`SyncDealInvoices`). Покупатель по договору прежнего
 * менеджера к новому не переходит — новый называет своего (`AskBuyer`).
 */
final class HandOverGarage
{
    public function __construct(private SyncDealInvoices $sync, private VoidInvoice $void, private Give $give) {}

    /** Почему передать нельзя; null — можно. Им же решает «⋯» и плашка держателя (`CarView`). */
    public static function blocked(Car $car): ?string
    {
        $deal = $car->deal;

        return match (true) {
            ! $deal || $deal->state === DealState::Cancelled || ! $deal->isGarage() => 'Передать можно ТС в гараже',
            $car->sold_at !== null || in_array($car->state, [CarState::Sold, CarState::Settled], true) => 'ТС уже продана',
            $car->invoice_id !== null => 'Расчёт по ТС уже выставлен',
            default => null,
        };
    }

    public function __invoke(Car $car, ?User $to, ?int $buyerPrice, User $by): Car
    {
        return DB::transaction(function () use ($car, $to, $buyerPrice, $by) {
            $car = Car::whereKey($car->id)->lockForUpdate()->with(['manager', 'offer.vendor'])->firstOrFail();
            $deal = Deal::whereKey($car->deal_id)->lockForUpdate()->first();
            $car->setRelation('deal', $deal);
            if ($why = self::blocked($car)) {
                throw ValidationException::withMessages(['holder' => $why]);
            }
            $this->check($car, $to, $buyerPrice);
            $offer = $car->offer;
            $from = $car->manager;
            $was = clone $deal;
            $payer = match (true) {
                $to === null || $to->holdsAsBuyer() => GaragePayer::Us,
                $offer->garagePayerFixed() === GaragePayer::Manager => GaragePayer::Manager,
                default => $deal->garage_payer,
            };

            // Счёт прежнему покупателю с гаражом, за который не платили, — аннулируется: ТС больше не его.
            if ($car->forBuyer() && ($old = $car->holderInvoice()) && ! $car->buyerHasPaid()) {
                ($this->void)($old, $by, 'ТС передана другому держателю');
            }
            // Держателю цену продажи назначают заново (26.11.2026): прежняя цена менеджера не встаёт ему в счёт и договор сама.
            $deal->update(['buyer_id' => $to?->id, 'bid_id' => null, 'garage_payer' => $payer,
                ...($to?->holdsAsBuyer() ? ['amount' => null, 'commission' => null] : []),
                // Наша доля по ссылке — у «платит менеджер» не Совкомбанка; держатель сменился — доля та же, плательщик — нет.
                'share' => $payer === GaragePayer::Manager ? $deal->share : null, 'buyer_asked_at' => null]);
            // Покупатель по договору — клиент прежнего менеджера: новому он не подходит (новый назовёт своего).
            DealContract::where('deal_id', $deal->id)->whereNotNull('buyer_user_id')->update(['buyer_user_id' => null]);
            $car->update(['manager_id' => $to?->id, 'buyer_price' => $to?->holdsAsBuyer() ? $buyerPrice : null]);
            $car->log($by, array_filter(['do' => 'holder', 'to' => $to?->name, 'from' => $from?->name,
                'price' => $to?->holdsAsBuyer() ? $buyerPrice : null], fn ($v) => $v !== null));

            $deal = $deal->fresh(['offer']);
            // Сменилась ветка маршрута («платим мы» ↔ «платит менеджер») у идущей сделки — этап своей ветки (`Give::handOver`).
            if ($deal->isActive() && $was->isGarageUs() !== $deal->isGarageUs()) {
                $this->give->handOver($offer->fresh(), $was, $deal, $by);
            }
            ($this->sync)($deal->fresh(), $by);
            // Договор ПРАЙМ теперь нужен (машину отдали держателю) — собственник из договора комиссии сам (27.11.2026, Tiggo
            // 890289/2026: при «взяли под себя» договора не было, разбор не звали, и собственника вписывали руками).
            DB::afterCommit(fn () => app(\App\Offers\Actions\FillCommissionOwner::class)($deal->fresh(['offer.vendor', 'contract'])));
            if ($deal->isActive() && $deal->isCommission()) {
                app(AskBuyer::class)->ask($deal);
            }
            $car = $car->fresh(['deal.offer', 'manager']);
            GarageChanged::dispatch($car);

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
