<?php

namespace App\Offers\Actions;

use App\Notifications\DealOpenedNotice;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\Notification;

/**
 * Показ всем менеджерам, пока менеджер сделки думает о новой закупочной (04.11.2026, владелец: «пока он думает — опубликовать
 * снова в предложениях для всех менеджеров на неделю, согласится — сразу убрать»). Машина остаётся в сделке; менеджерам она —
 * обычное предложение со сроком до конца показа (`Offer::showsDealAsOpen`), подтверждение встаёт резервом (`PlaceBid` в `Sold`).
 * Включает `ApplyReprice` («Спросить» по сделке с галкой), снимает «Согласен» (`AnswerReprice`) или «Снять показ» в CRM.
 */
final class ShowDealToAll
{
    /** Сколько дней показывать: «3 дня | Неделя» в шторке переоценки. */
    public const DAYS = [3, 7];

    public function start(Offer $offer, int $days, ?User $by): void
    {
        if ($offer->state !== OfferState::Sold || ! $offer->deal || $offer->is_demo) {
            return;
        }
        $until = now()->addDays($days);
        $offer->forceFill(['deal_show_all_at' => now(), 'deal_show_until' => $until])->save();
        $offer->log(OfferEventType::Updated, $by, ['show_all' => 'Показ всем менеджерам до '.\App\Support\Ago::at($until, true, words: false)]);
        $managers = User::withRole(Role::Manager)->whereKeyNot($offer->deal->buyer_id)->get();
        Notification::send($managers, new DealOpenedNotice($offer, plain: true));
    }

    /** Снять показ: машина уходит из предложений у всех, кроме тех, кто уже подтвердил (резерв держит страницу). */
    public function stop(Offer $offer, ?User $by, string $why = 'Показ всем менеджерам снят'): void
    {
        if ($offer->deal_show_all_at === null) {
            return;
        }
        $offer->forceFill(['deal_show_all_at' => null, 'deal_show_until' => $offer->dealAudience()->exists() ? $offer->deal_show_until : null])->save();
        $offer->log(OfferEventType::Updated, $by, ['show_all' => $why]);
        RetractOfferNotices::dispatch($offer->id);
    }
}
