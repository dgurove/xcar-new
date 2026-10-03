<?php

namespace App\Offers\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\OfferPublishedNotice;
use App\Notifications\PurchaseCarOnSaleNotice;
use App\Notifications\SlotPublishedNotice;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Purchases\Car as PurchaseCar;
use App\Purchases\OfferState as PurchaseOfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * «Новое предложение» тем, чья волна наступила и кому о нём ещё не писали. Публикация «сейчас» зовёт сразу, часы
 * (offers:tick) — для слота и следующих волн; тогда же карточка въезжает им в каталог. Часы пишут человеку одно
 * уведомление на всё, что открылось ему за тик: «Опубликовано 17 предложений» (слот, владелец 03.10.2026), одно —
 * как раньше. Вышло из закупки по контрпредложению — тем, кто называл за ТС цену в закупке, своё «ТС из закупки
 * в продаже» на каждое.
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
        Notification::send($managers, new OfferPublishedNotice($offer));
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
            foreach ($ids as $id) {
                $fresh[$id][] = $offer;
            }
            $this->publish->card($offer->number, Topics::CATALOG);
        }
        foreach ($fresh as $id => $list) {
            $users->get($id)?->notify(count($list) === 1 ? new OfferPublishedNotice($list[0]) : new SlotPublishedNotice(count($list)));
        }

        return $rows->count();
    }
}
