<?php

namespace App\Purchases\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Purchases\Car;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Цена покупателя за машину: одна живая на человека, новая заменяет прежнюю. */
final class PlaceOffer
{
    public function __invoke(Car $car, User $by, int $amount, ?string $comment = null): Bid
    {
        if (! $car->purchase->acceptsOffers() || $car->offer) {
            throw ValidationException::withMessages(['amount' => 'Приём цен закрыт']);
        }
        if ($amount < 1000) {
            throw ValidationException::withMessages(['amount' => 'Назовите цену']);
        }

        if ($car->bids()->where('user_id', $by->id)->where('state', BidState::Accepted)->exists()) {
            throw ValidationException::withMessages(['amount' => 'Ваша цена уже выбрана']);
        }

        return DB::transaction(function () use ($car, $by, $amount, $comment) {
            $was = $car->bids()->where('user_id', $by->id)->where('state', BidState::Active)->value('amount');
            // Прежняя цена не отозвана, а заменена новой — как у подтверждения продажи.
            $car->bids()->where('user_id', $by->id)->where('state', BidState::Active)->update(['state' => BidState::Replaced]);
            $bid = Bid::create(['purchase_car_id' => $car->id, 'kind' => BidKind::Purchase, 'user_id' => $by->id, 'amount' => $amount, 'comment' => $comment ?: null]);
            $car->log(OfferEventType::BidPlaced, $by, array_filter(['amount' => $amount, 'was' => $was]));
            app(Publisher::class)->refresh(Topics::STAFF, ["/purchases/{$car->purchase->number}", "/purchases/{$car->purchase->number}/{$car->ref}"]);

            return $bid;
        });
    }
}
