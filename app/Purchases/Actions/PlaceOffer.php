<?php

namespace App\Purchases\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Purchases\Car;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Цена покупателя за машину: одна живая на человека, новая заменяет прежнюю. */
final class PlaceOffer
{
    public function __invoke(Car $car, User $by, int $amount, ?string $comment = null): Offer
    {
        if (! $car->purchase->acceptsOffers()) {
            throw ValidationException::withMessages(['amount' => 'Приём цен закрыт']);
        }
        if ($amount < 1000) {
            throw ValidationException::withMessages(['amount' => 'Назовите цену']);
        }

        if (Offer::where('car_id', $car->id)->where('user_id', $by->id)->where('state', OfferState::Chosen)->exists()) {
            throw ValidationException::withMessages(['amount' => 'Ваша цена уже выбрана']);
        }

        return DB::transaction(function () use ($car, $by, $amount, $comment) {
            Offer::where('car_id', $car->id)->where('user_id', $by->id)->where('state', OfferState::Active)->update(['state' => OfferState::Withdrawn]);
            $offer = Offer::create(['car_id' => $car->id, 'user_id' => $by->id, 'amount' => $amount, 'comment' => $comment ?: null]);
            app(Publisher::class)->refresh(Topics::STAFF, ["/purchases/{$car->purchase->number}", "/purchases/{$car->purchase->number}/{$car->ref}"]);

            return $offer;
        });
    }
}
