<?php

namespace App\Offers\Actions;

use App\Notifications\DealOpenedNotice;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Показ всем менеджерам, пока менеджер сделки думает о новой закупочной (04.11.2026, владелец: «пока он думает — опубликовать
 * снова в предложениях для всех менеджеров на неделю, согласится — сразу убрать»). Машина остаётся в сделке; менеджерам она —
 * обычное предложение со сроком до конца показа (`Offer::showsDealAsOpen`), подтверждение встаёт резервом (`PlaceBid` в `Sold`).
 * Включает `ApplyReprice` («Спросить» по сделке с галкой) и пункт «Опубликовать для других менеджеров…» в «⋯» любой идущей
 * сделки, гаражной тоже (12.11.2026, владелец: «она в сделке у этого менеджера, но начальник хочет опубликовать её для
 * других», шторка `x-offer.show-all-sheet`); снимает «Согласен» (`AnswerReprice`) или «Снять публикацию» в CRM.
 */
final class ShowDealToAll
{
    /** Сколько дней показывать: «3 дня | Неделя» в шторке переоценки. */
    public const DAYS = [3, 7];

    /** Можно ли показать: идущая сделка (и гаражная, пока машину не привезли), не аукцион, не демо. */
    public static function allows(Offer $offer): bool
    {
        return $offer->state === OfferState::Sold && $offer->deal?->isActive() && ! $offer->deal->isAuction() && ! $offer->is_demo;
    }

    /** $until — дни («3 дня | Неделя» переоценки) или дата приёма (шторка «Опубликовать…»). */
    public function start(Offer $offer, int|CarbonInterface $until, ?User $by): void
    {
        if (! self::allows($offer)) {
            return;
        }
        $until = is_int($until) ? now()->addDays($until) : $until;
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
