<?php

namespace App\Offers\Actions;

use App\Offers\Events\OffersHidden;
use App\Offers\Offer;
use App\Offers\Showing;
use App\Users\BuyerGroup;
use App\Users\User;

/** Менеджер закрыл предложение покупателю или группе: показ снимается, у кого оно пропало — карточка уходит из ленты. */
final class HideOffers
{
    /** @param list<int> $offerIds */
    public function __invoke(User $manager, array $offerIds, ?User $buyer = null, ?BuyerGroup $group = null): int
    {
        if (($buyer && $buyer->manager_id !== $manager->id) || ($group && $group->manager_id !== $manager->id) || (! $buyer && ! $group)) {
            return 0;
        }
        $offers = Offer::whereIn('id', $offerIds)->get();
        $gone = [];
        $removed = 0;
        foreach ($offers as $offer) {
            $before = Showing::buyerIdsOf($offer, $manager->id)->all();
            $removed += Showing::where('offer_id', $offer->id)->where('manager_id', $manager->id)
                ->when($buyer, fn ($q) => $q->where('user_id', $buyer->id))
                ->when($group, fn ($q) => $q->where('group_id', $group->id))
                ->delete();
            foreach (array_diff($before, Showing::buyerIdsOf($offer, $manager->id)->all()) as $id) {
                $gone[$id][] = $offer->number;
            }
        }
        if ($gone) {
            OffersHidden::dispatch($manager, $gone);
        }

        return $removed;
    }
}
