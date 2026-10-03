<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\DeclineBid;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отдать машину в гараж руками, минуя маршрут: предложение уходит с витрины в состояние «В гараже», рядом
 * появляется строка гаража — кому отдали, за сколько и с какого этапа (машина бывает уже у нас или у менеджера).
 * Без менеджера — взяли под себя. «Ждёт страховую» — через маршрут, это `SendViaRoute`.
 */
final class TakeToGarage
{
    public function __construct(private ChangeOfferState $state) {}

    public function __invoke(Offer $offer, ?User $manager, ?int $cost, User $by, ?string $note = null, CarState $stage = CarState::Repair): Car
    {
        if ($manager && ! $manager->isManager()) {
            throw ValidationException::withMessages(['manager_id' => 'В гараж ТС берёт менеджер']);
        }
        if ($offer->state === OfferState::Sold) {
            throw ValidationException::withMessages(['state' => 'Идёт сделка: сначала отмените её']);
        }
        if (! $stage->isWorking()) {
            throw ValidationException::withMessages(['stage' => 'Руками — с доставки, подготовки или продажи']);
        }

        return DB::transaction(function () use ($offer, $manager, $cost, $by, $note, $stage) {
            ($this->state)($offer, OfferState::Garage, $by);
            // Машина ушла из продажи — ждущие подтверждения менеджеров закрываются, как при принятии чужого.
            $offer->bids()->where('state', BidState::Active)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));

            $car = Car::create([
                'offer_id' => $offer->id,
                'manager_id' => $manager?->id,
                'state' => $stage,
                'taken_at' => now(),
                'stage_at' => now(),
                'history' => [[$stage->value, now()->toIso8601String()]],
                'cost' => $cost,
                'note' => $note,
                'created_by' => $by->id,
            ]);
            GarageChanged::dispatch($car);

            return $car;
        });
    }
}
