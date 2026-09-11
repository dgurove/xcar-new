<?php

namespace App\Purchases\Actions;

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

        return DB::transaction(function () use ($car, $by, $amount, $comment) {
            Offer::where('car_id', $car->id)->where('user_id', $by->id)->whereIn('state', [OfferState::Active, OfferState::Chosen])->update(['state' => OfferState::Withdrawn]);
            $offer = Offer::create(['car_id' => $car->id, 'user_id' => $by->id, 'amount' => $amount, 'comment' => $comment ?: null]);
            app(\App\Live\Publisher::class)->refresh(\App\Live\Topics::STAFF, ["/admin/zakupki/{$car->purchase->number}", "/admin/zakupki/{$car->purchase->number}/{$car->ref}"]);

            return $offer;
        });
    }
}
