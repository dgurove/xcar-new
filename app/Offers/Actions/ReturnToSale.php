<?php

namespace App\Offers\Actions;

use App\Garage\Car as GarageCar;
use App\Garage\Events\GarageChanged;
use App\Offers\AudienceRules;
use App\Offers\Binding;
use App\Offers\CarPlace;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Вернуть в продажу» (11.10.2026, владелец: «отдали в сделку либо в гараж — и нигде нет кнопки обратно в продажу», «с
 * возможностью переоценить — новая закупочная, заявленная и продажная»): машина в сделке или в гараже снова открыта
 * менеджерам с новыми ценами. Идущая сделка отменяется (`CancelDeal`: подтверждение снова ждёт, неоплаченные счета
 * гаснут), строка гаража стирается; цены — `UpdateOffer`, публикация — `ChangeOfferState` (срок приёма — новый).
 * Гаражная машина, по которой менеджер уже тратил или продал её, — не молча: сначала разбирают расходы и продажу.
 * Так же — черновик с аукциона («Вернуть в продажу…», 13.10.2026): цены, срок приёма и «Кому показывать» одной шторкой.
 * Машина уже стоит у менеджера (21.10.2026, Иван Заикин: взял в гараж, привёз, чинить не стал — «отдать другому в
 * продажу») — `$stays`: она остаётся у него (`offers.held_by`), новая сделка забирает её у него; город и адрес осмотра —
 * его (`$where`).
 */
final class ReturnToSale
{
    public function __construct(private UpdateOffer $update, private ChangeOfferState $change, private CancelDeal $cancel) {}

    /**
     * @param  array{floor_price?: ?int, publish_price?: ?int, asking_price?: ?int}  $prices
     * @param  ?CarbonInterface  $closeAt  до когда приём (шторка «Отменить сделку → В продажу»); пусто — через 3 дня в 17:00
     * @param  ?array  $audience  волны «Кому показывать» (`AudienceRules`); null — не трогать, [] — как у вендора
     * @param  array{settlement_id?: ?int, inspection_address?: ?string}  $where  где машина стоит, когда она остаётся у менеджера
     */
    public function __invoke(Offer $offer, User $by, array $prices, ?CarbonInterface $closeAt = null, ?array $audience = null, bool $stays = false, array $where = []): Offer
    {
        $auction = $offer->onAuction();
        if (! $auction && ! in_array($offer->state, [OfferState::Sold, OfferState::Garage], true)) {
            throw ValidationException::withMessages(['state' => 'ТС не в сделке, не в гараже и не на аукционе']);
        }
        $car = GarageCar::where('offer_id', $offer->id)->first();
        if ($car?->isSold()) {
            throw ValidationException::withMessages(['state' => 'Менеджер уже продал ТС из гаража: сначала снимите продажу']);
        }
        // У покупателя с гаражом расходы — его (20.10.2026): машину у него забрать можно, пока он не платил.
        if ($car?->forBuyer() ? $car->buyerHasPaid() : $car?->costs()->exists()) {
            throw ValidationException::withMessages(['state' => $car->forBuyer() ? 'Покупатель уже платил: сначала верните оплату' : 'По ТС в гараже есть расходы: сначала уберите их']);
        }

        if ($error = Binding::closeError($offer, $closeAt)) {
            throw ValidationException::withMessages(['bids_close_at' => $error]);
        }

        // Кто держит машину — до того, как сделка и строка гаража уйдут: потом его не узнать.
        $holder = $stays && $offer->car_place === CarPlace::Keeper ? $offer->standsWith() : null;

        return DB::transaction(function () use ($offer, $by, $prices, $car, $closeAt, $audience, $auction, $holder, $where) {
            // Гаражная сделка — и закончившаяся (машина уже в гараже), как у «Отдали по ошибке»: машины у менеджера больше
            // нет, «завершённой» сделки без неё в его истории не остаётся. Строка гаража уходит здесь же: `CancelDeal`
            // молча снимает только ждущую машину без расходов.
            $deal = $car?->deal ?? $offer->deal()->first();
            if ($deal && $deal->state !== DealState::Cancelled) {
                // Подтверждение победителя снова ждёт (12.10.2026): менеджер его не отзывал, сорвалась сделка.
                ($this->cancel)($deal, $by, keepCar: true, keepBid: true);
                $offer->unsetRelation('deal');
            }
            if ($car) {
                $car->delete();
                GarageChanged::dispatch($car);
            }
            // Заявленная пустая — снова от закупочной (`Offer::declaredPrice`), поэтому null пишется как есть. Пустая
            // закупочная — прежняя: стереть её шторкой нельзя (приборка 26.10.2026).
            $data = array_intersect_key($prices, array_flip(['floor_price', 'publish_price', 'asking_price']));
            if (($data['floor_price'] ?? null) === null) {
                unset($data['floor_price']);
            }
            if ($audience !== null) {
                $data['audience_rules'] = AudienceRules::normalize($audience) ?: null;
            }
            if ($holder) {
                $data += array_intersect_key($where, array_flip(['settlement_id', 'inspection_address']));
            }
            ($this->update)($offer, $data, $by, reprice: true);
            if ($holder) {
                Offer::whereKey($offer->id)->update(['held_by' => $holder->id]);
                $offer->log(OfferEventType::Note, $by, ['text' => 'Стоит у '.$holder->shortName().', забирать у него']);
            }

            // Приём — выбранный срок, иначе заново, как у публикации (через 3 дня в 17:00, `ChangeOfferState`).
            Offer::whereKey($offer->id)->update(['bids_close_at' => $closeAt?->isFuture() ? $closeAt : null]);
            // С аукциона (13.10.2026): `auction_at` гасит сам переход из черновика, в «Истории» — куда ушла.
            if ($auction) {
                $offer->log(OfferEventType::Auction, $by, ['off' => true, 'to' => 'sale']);
            }

            return ($this->change)($offer->refresh(), OfferState::Open, $by);
        });
    }
}
