<?php

namespace App\Offers\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\OfferPublishedNotice;
use App\Notifications\PurchaseCarOnSaleNotice;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Purchases\Car as PurchaseCar;
use App\Purchases\OfferState as PurchaseOfferState;
use App\Users\User;
use Illuminate\Support\Facades\Notification;

/**
 * «Новое предложение» тем, чья волна наступила и кому о нём ещё не писали. Публикация зовёт сразу, часы
 * (offers:tick) — для следующих волн; тогда же карточка въезжает им в каталог. Вышло из закупки по
 * контрпредложению — тем, кто называл за ТС цену в закупке, своё «ТС из закупки в продаже».
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

    /** Часы: у открытых — наступившие волны. */
    public function due(): int
    {
        $sent = 0;
        foreach (Offer::where('state', OfferState::Open)
            ->whereHas('viewers', fn ($v) => $v->whereNull('notified_at')->where('opens_at', '<=', now()))->lazyById() as $offer) {
            $sent += $this($offer, live: true);
        }

        return $sent;
    }
}
