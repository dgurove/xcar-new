<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Offers\Actions\ChangeOfferState;
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
            throw ValidationException::withMessages(['manager_id' => 'В гараж машину берёт менеджер']);
        }

        return DB::transaction(function () use ($offer, $manager, $cost, $by, $note) {
            ($this->state)($offer, OfferState::Garage, $by);

            return Car::create([
                'offer_id' => $offer->id,
                'manager_id' => $manager?->id,
                'taken_at' => now(),
                'cost' => $cost,
                'note' => $note,
                'created_by' => $by->id,
            ]);
        });
    }
}
