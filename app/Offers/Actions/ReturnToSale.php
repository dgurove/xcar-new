<?php

namespace App\Offers\Actions;

use App\Garage\Car as GarageCar;
use App\Garage\Events\GarageChanged;
use App\Offers\AudienceRules;
use App\Offers\DealState;
use App\Offers\Offer;
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
 */
final class ReturnToSale
{
    public function __construct(private UpdateOffer $update, private ChangeOfferState $change, private CancelDeal $cancel) {}

    /**
     * @param  array{floor_price?: ?int, publish_price?: ?int, asking_price?: ?int}  $prices
     * @param  ?CarbonInterface  $closeAt  до когда приём (шторка «Отменить сделку → В продажу»); пусто — через 3 дня в 17:00
     * @param  ?array  $audience  волны «Кому показывать» (`AudienceRules`); null — не трогать, [] — как у вендора
     */
    public function __invoke(Offer $offer, User $by, array $prices, ?CarbonInterface $closeAt = null, ?array $audience = null): Offer
    {
        if (! in_array($offer->state, [OfferState::Sold, OfferState::Garage], true)) {
            throw ValidationException::withMessages(['state' => 'ТС не в сделке и не в гараже']);
        }
        $car = GarageCar::where('offer_id', $offer->id)->first();
        if ($car?->isSold()) {
            throw ValidationException::withMessages(['state' => 'Менеджер уже продал ТС из гаража: сначала снимите продажу']);
        }
        if ($car?->costs()->exists()) {
            throw ValidationException::withMessages(['state' => 'По ТС в гараже есть расходы: сначала уберите их']);
        }

        return DB::transaction(function () use ($offer, $by, $prices, $car, $closeAt, $audience) {
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
            // Заявленная пустая — снова от закупочной (`Offer::declaredPrice`), поэтому null пишется как есть.
            $data = array_intersect_key($prices, array_flip(['floor_price', 'publish_price', 'asking_price']));
            if ($audience !== null) {
                $data['audience_rules'] = AudienceRules::normalize($audience) ?: null;
            }
            ($this->update)($offer, $data, $by);

            // Приём — выбранный срок, иначе заново, как у публикации (через 3 дня в 17:00, `ChangeOfferState`).
            Offer::whereKey($offer->id)->update(['bids_close_at' => $closeAt?->isFuture() ? $closeAt : null]);

            return ($this->change)($offer->refresh(), OfferState::Open, $by);
        });
    }
}
