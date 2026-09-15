<?php

namespace App\Offers\Actions;

use App\Offers\Events\OffersHidden;
use App\Offers\Events\OffersShown;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Users\BuyerGroup;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Менеджер открывает предложения покупателям и группам. Пачка — только
 * добавляет; `sync` для одного предложения — приводит показы к отмеченному
 * списку, снимая лишние. Уведомление получают только те, кому предложение
 * стало видно впервые (лично или через группу).
 */
final class ShowOffers
{
    /**
     * @param  list<int>  $offerIds
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{shown: int, buyers: int}
     */
    public function __invoke(User $manager, array $offerIds, array $userIds, array $groupIds, bool $sync = false): array
    {
        $offers = Offer::whereIn('id', $offerIds)->where('state', OfferState::Open)->visibleTo($manager)->get();
        if ($offers->isEmpty()) {
            throw ValidationException::withMessages(['offers' => 'Эти предложения вам не открыты']);
        }
        $users = User::whereIn('id', $userIds)->where('manager_id', $manager->id)->pluck('id')->all();
        $groups = BuyerGroup::whereIn('id', $groupIds)->where('manager_id', $manager->id)->pluck('id')->all();

        $fresh = [];
        $gone = [];
        DB::transaction(function () use ($manager, $offers, $users, $groups, $sync, &$fresh, &$gone) {
            foreach ($offers as $offer) {
                $before = Showing::buyerIdsOf($offer, $manager->id)->all();
                if ($sync) {
                    Showing::where('offer_id', $offer->id)->where('manager_id', $manager->id)
                        ->where(fn ($w) => $w->whereNotIn('user_id', $users ?: [0])->orWhereNull('user_id'))
                        ->where(fn ($w) => $w->whereNotIn('group_id', $groups ?: [0])->orWhereNull('group_id'))
                        ->delete();
                }
                $rows = [
                    ...array_map(fn ($u) => ['offer_id' => $offer->id, 'manager_id' => $manager->id, 'user_id' => $u, 'group_id' => null, 'created_at' => now()], $users),
                    ...array_map(fn ($g) => ['offer_id' => $offer->id, 'manager_id' => $manager->id, 'user_id' => null, 'group_id' => $g, 'created_at' => now()], $groups),
                ];
                if ($rows) {
                    // Повтор — не ошибка: частичные уникальные индексы Eloquent не знает, поэтому вставка с игнором.
                    Showing::insertOrIgnore($rows);
                }
                $after = Showing::buyerIdsOf($offer, $manager->id)->all();
                foreach (array_diff($after, $before) as $buyer) {
                    $fresh[$buyer][] = $offer->id;
                }
                foreach (array_diff($before, $after) as $buyer) {
                    $gone[$buyer][] = $offer->number;
                }
            }
        });

        if ($fresh) {
            OffersShown::dispatch($manager, $offers->pluck('id')->all(), $fresh);
        }
        if ($gone) {
            OffersHidden::dispatch($manager, $gone);
        }

        return ['shown' => $offers->count(), 'buyers' => count($fresh)];
    }
}
