<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Events\BidDeclined;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отдать машину в гараж: предложение уходит с витрины в состояние «В гараже», рядом
 * появляется строка гаража — кому отдали и за сколько. Без менеджера — взяли под себя.
 */
final class TakeToGarage
{
    public function __construct(private ChangeOfferState $state) {}

    public function __invoke(Offer $offer, ?User $manager, ?int $cost, User $by, ?string $note = null): Car
    {
        if ($manager && ! $manager->isManager()) {
            throw ValidationException::withMessages(['manager_id' => 'В гараж ТС берёт менеджер']);
        }

        return DB::transaction(function () use ($offer, $manager, $cost, $by, $note) {
            ($this->state)($offer, OfferState::Garage, $by);
            // Машина ушла из продажи — ждущие подтверждения менеджеров закрываются, как при принятии чужого.
            $offer->bids()->where('state', BidState::Active)->get()->each(function (Bid $bid) use ($by) {
                $bid->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by->id]);
                BidDeclined::dispatch($bid, $by);
            });

            $car = Car::create([
                'offer_id' => $offer->id,
                'manager_id' => $manager?->id,
                'taken_at' => now(),
                'cost' => $cost,
                'note' => $note,
                'created_by' => $by->id,
            ]);
            GarageChanged::dispatch($car);

            return $car;
        });
    }
}
