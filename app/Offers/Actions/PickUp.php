<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;
use Illuminate\Validation\ValidationException;

/** «Забрал»: ответственный за вывоз (или сотрудник за него) отмечает, что ТС у него / у нас. */
final class PickUp
{
    public function __construct(private TakeExit $take) {}

    public function __invoke(Offer $offer, User $by): Offer
    {
        if (! $by->isAdmin() && $offer->evacuator_id !== $by->id) {
            throw ValidationException::withMessages(['exit' => 'Вывоз поручен не вам']);
        }
        // Альфа — только после оплаты (10.10.2026): сотрудник отметит факт и сам, менеджеру рано.
        if (! $by->isAdmin() && $offer->deal && ! $offer->deal->mayPickUp()) {
            throw ValidationException::withMessages(['exit' => 'Забирать можно после оплаты']);
        }
        $exit = $offer->position(Track::Service)?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->first();
        if (! $exit) {
            throw ValidationException::withMessages(['exit' => 'Забирать пока рано']);
        }

        return ($this->take)($offer, $exit, Actor::Keeper, $by);
    }
}
