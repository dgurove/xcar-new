<?php

namespace App\Offers\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\BuyerOffersNotice;
use App\Notifications\BuyerWaitedNotice;
use App\Notifications\GalleryInterestOnSaleNotice;
use App\Notifications\OfferPublishedNotice;
use App\Notifications\PurchaseCarOnSaleNotice;
use App\Notifications\SlotPublishedNotice;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Purchases\Car as PurchaseCar;
use App\Purchases\OfferState as PurchaseOfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * «Новое предложение» тем, чья волна наступила и кому о нём ещё не писали. Публикация «сейчас» зовёт сразу, часы
 * (offers:tick) — для слота и следующих волн; тогда же карточка въезжает им в каталог. Часы пишут человеку одно
 * уведомление на всё, что открылось ему за тик: «Опубликовано 17 предложений» (слот, владелец 03.10.2026), одно —
 * как раньше. Вышло из закупки по контрпредложению — тем, кто называл за ТС цену в закупке, своё «ТС из закупки
 * в продаже» на каждое. Покупателям менеджера (12.10.2026) — вместе с волной менеджера: видящим всё — «Новое
 * предложение» одним на пачку (или «В продаже, вы отмечали интерес»), а «выбранным», что ждали машину из галереи, —
 * менеджеру «ждал, откройте».
 */
final class NotifyViewers
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(Offer $offer, bool $live = false): int
    {
        if ($offer->state !== OfferState::Open) {
            return 0;
        }
        $due = OfferViewer::where('offer_id', $offer->id)->whereNull('notified_at')->where('opens_at', '<=', now())->pluck('user_id')->all();
        if (! $due) {
            return 0;
        }
        OfferViewer::where('offer_id', $offer->id)->whereIn('user_id', $due)->update(['notified_at' => now()]);
        $managers = User::whereIn('id', $due)->get();

        if ($car = PurchaseCar::where('offer_id', $offer->id)->first()) {
            $priced = $car->offers()->where('state', '!=', PurchaseOfferState::Withdrawn)->pluck('user_id')->all();
            [$ours, $managers] = $managers->partition(fn ($u) => in_array($u->id, $priced, true));
            Notification::send($ours, new PurchaseCarOnSaleNotice($offer));
            if (! $car->announced_at) {
                $car->update(['announced_at' => now()]);
            }
        }
        // Отмечали интерес в галерее — своё «В продаже, вы отмечали интерес» вместо «Нового предложения» (12.10.2026).
        $liked = self::galleryInterest($offer);
        [$ours, $managers] = $managers->partition(fn ($u) => in_array($u->id, $liked, true));
        Notification::send($ours, new GalleryInterestOnSaleNotice($offer));
        Notification::send($managers, new OfferPublishedNotice($offer));
        $this->buyers([$offer->id => $due], collect([$offer->id => $offer]));
        if ($live) {
            $this->publish->card($offer->number, Topics::CATALOG);
        }

        return count($due);
    }

    /** Часы: у открытых — наступившие волны и вышедший слот, по человеку одним уведомлением. */
    public function due(): int
    {
        // Отметка и выборка одним запросом: публикация «сейчас» в другом процессе не напишет второй раз.
        $rows = collect(DB::select(
            'update offer_viewers set notified_at = ? where notified_at is null and opens_at <= ?
                and offer_id in (select id from offers where state = ? and is_demo = false) returning offer_id, user_id',
            [now(), now(), OfferState::Open->value],
        ));
        if ($rows->isEmpty()) {
            return 0;
        }
        $offers = Offer::whereIn('id', $rows->pluck('offer_id')->unique())->get()->keyBy('id');
        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');
        $fresh = [];
        foreach ($rows->groupBy('offer_id') as $offerId => $viewers) {
            $offer = $offers->get($offerId);
            if (! $offer) {
                continue;
            }
            $ids = $viewers->pluck('user_id')->all();
            if ($car = PurchaseCar::where('offer_id', $offer->id)->first()) {
                $priced = $car->offers()->where('state', '!=', PurchaseOfferState::Withdrawn)->pluck('user_id')->all();
                Notification::send($users->only(array_intersect($ids, $priced))->values(), new PurchaseCarOnSaleNotice($offer));
                $ids = array_diff($ids, $priced);
                if (! $car->announced_at) {
                    $car->update(['announced_at' => now()]);
                }
            }
            $liked = array_intersect($ids, self::galleryInterest($offer));
            Notification::send($users->only($liked)->values(), new GalleryInterestOnSaleNotice($offer));
            foreach (array_diff($ids, $liked) as $id) {
                $fresh[$id][] = $offer;
            }
            $this->publish->card($offer->number, Topics::CATALOG);
        }
        foreach ($fresh as $id => $list) {
            $users->get($id)?->notify(count($list) === 1 ? new OfferPublishedNotice($list[0]) : new SlotPublishedNotice(count($list)));
        }
        $this->buyers($rows->groupBy('offer_id')->map(fn ($v) => $v->pluck('user_id')->all())->all(), $offers);

        return $rows->count();
    }

    /**
     * Покупатели менеджеров, чья волна наступила. Видящий всё — одно уведомление на всё, что открылось ему за раз;
     * ждал машину из галереи — своё. «Выбранный», что ждал, машину не видит — менеджеру «ждал, откройте».
     *
     * @param  array<int, list<int>>  $opened  offer_id → кому открылось (менеджеры)
     * @param  Collection<int, Offer>  $offers
     */
    private function buyers(array $opened, Collection $offers): void
    {
        $managers = array_values(array_unique(array_merge(...array_values($opened))));
        if (! $managers) {
            return;
        }
        $buyers = User::withRole(Role::Buyer)->whereIn('manager_id', $managers)->whereNull('rejected_at')->get()->groupBy('manager_id');
        if ($buyers->isEmpty()) {
            return;
        }
        $fresh = [];
        foreach ($opened as $offerId => $ids) {
            $offer = $offers->get($offerId);
            if (! $offer) {
                continue;
            }
            $liked = self::galleryInterest($offer);
            foreach ($ids as $managerId) {
                foreach ($buyers->get($managerId, collect()) as $buyer) {
                    $waited = in_array($buyer->id, $liked, true);
                    if ($buyer->buyerSeesAll() && $waited) {
                        $buyer->notify(new GalleryInterestOnSaleNotice($offer, buyer: true));
                    } elseif ($buyer->buyerSeesAll()) {
                        $fresh[$buyer->id][] = $offer->id;
                    } elseif ($waited && ! $offer->isVisibleTo($buyer)) {
                        User::find($managerId)?->notify(new BuyerWaitedNotice($buyer, $offer));
                    }
                }
            }
        }
        $all = $buyers->flatten(1)->keyBy('id');
        foreach ($fresh as $id => $list) {
            $all->get($id)?->notify(new BuyerOffersNotice($list));
        }
    }

    /** @return list<int> кто проявлял интерес к машине, пока она была в галерее */
    private static function galleryInterest(Offer $offer): array
    {
        return $offer->gallery_from ? Interest::where('offer_id', $offer->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];
    }
}
