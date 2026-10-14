<?php

namespace App\Offers\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\BuyerWaitedNotice;
use App\Notifications\GalleryInterestOnSaleNotice;
use App\Notifications\OfferPublishedNotice;
use App\Notifications\SlotPublishedNotice;
use App\Notifications\UrgentOfferNotice;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Purchases\Car as PurchaseCar;
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
 *
 * Общих «Новое предложение» нет (13.10.2026, владелец: «их там сотни каждый день, с ума можно сойти»): пишем только о
 * срочных — всем, и о «Открыты только Вам» — одному (`solo`: в волнах он один, как пачка в каталоге). Адресное
 * «вы отмечали интерес» остаётся, «ТС из закупки, где вы называли цену» — нет; покупателям общей рассылки тоже нет.
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
        // Срочное — всем одно громкое «Срочно: …» (12.10.2026), без «из закупки» и «вы отмечали интерес».
        if ($offer->isUrgent()) {
            $managers->each(fn (User $u) => $u->notify(new UrgentOfferNotice($offer, $u)));
            $this->buyers([$offer->id => $due], collect([$offer->id => $offer]));
            if ($live) {
                $this->publish->card($offer->number, Topics::CATALOG);
            }

            return count($due);
        }

        $this->announcePurchase($offer);
        // Отмечали интерес в галерее — своё «В продаже, вы отмечали интерес» вместо «Нового предложения» (12.10.2026).
        $liked = self::galleryInterest($offer);
        [$ours, $managers] = $managers->partition(fn ($u) => in_array($u->id, $liked, true));
        Notification::send($ours, new GalleryInterestOnSaleNotice($offer));
        if (self::solo($offer->id)) {
            Notification::send($managers, new OfferPublishedNotice($offer));
        }
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
            if ($offer->isUrgent()) {
                $users->only($ids)->each(fn (User $u) => $u->notify(new UrgentOfferNotice($offer, $u)));
                $this->publish->card($offer->number, Topics::CATALOG);

                continue;
            }
            $this->announcePurchase($offer);
            $liked = array_intersect($ids, self::galleryInterest($offer));
            Notification::send($users->only($liked)->values(), new GalleryInterestOnSaleNotice($offer));
            if (self::solo($offer->id)) {
                foreach (array_diff($ids, $liked) as $id) {
                    $fresh[$id][] = $offer;
                }
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
     * Покупатели менеджеров, чья волна наступила: срочное — всем, ждал машину из галереи — своё; общего «Новые
     * предложения» нет (13.10.2026). «Выбранный», что ждал, машину не видит — менеджеру «ждал, откройте».
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
        foreach ($opened as $offerId => $ids) {
            $offer = $offers->get($offerId);
            if (! $offer) {
                continue;
            }
            $liked = self::galleryInterest($offer);
            foreach ($ids as $managerId) {
                foreach ($buyers->get($managerId, collect()) as $buyer) {
                    // Срочное — каждому покупателю менеджера, и «выбранному» тоже (видно им всем).
                    if ($offer->isUrgent()) {
                        $buyer->notify(new UrgentOfferNotice($offer, $buyer));

                        continue;
                    }
                    $waited = in_array($buyer->id, $liked, true);
                    if ($buyer->buyerSeesAll() && $waited) {
                        $buyer->notify(new GalleryInterestOnSaleNotice($offer, buyer: true));
                    } elseif ($waited && ! $offer->isVisibleTo($buyer)) {
                        User::find($managerId)?->notify(new BuyerWaitedNotice($buyer, $offer));
                    }
                }
            }
        }
    }

    /** ТС из закупки вышла в продажу — только отметка; уведомления «вы называли цену» нет (владелец 13.10.2026). */
    private function announcePurchase(Offer $offer): void
    {
        PurchaseCar::where('car_id', $offer->car_id)->whereNull('announced_at')->update(['announced_at' => now()]);
    }

    /** «Открыты только Вам»: в волнах показа предложения ровно один человек (`Offer::scopeOnlyFor`). */
    private static function solo(int $offerId): bool
    {
        return OfferViewer::where('offer_id', $offerId)->count() === 1;
    }

    /** @return list<int> кто проявлял интерес к машине, пока она была в галерее */
    private static function galleryInterest(Offer $offer): array
    {
        return $offer->gallery_from ? Interest::where('offer_id', $offer->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];
    }
}
