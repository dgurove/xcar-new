<?php

namespace App\Offers\Actions;

use App\Offers\AudienceRules;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use Illuminate\Support\Facades\DB;

/**
 * Пересчитать, кто видит опубликованное предложение и с какого момента (от published_at). Вызывают публикация,
 * правка правил, смена состава групп и новый менеджер. Уже уведомлённым отметка остаётся; выпавшие из круга
 * теряют предложение сразу. Волну сдвинули на будущее (13.10.2026: «остальным через 2 дня» стало «через 5», а
 * 21 менеджеру уже пришло «Новое предложение») — это тоже «выпал»: анонс отзывается, отметка снимается, и уведомление
 * придёт, когда его волна наступит.
 */
final class SyncViewers
{
    /** $people — состав волн (`AudienceRules::openings`), общий на много предложений подряд. */
    public function __invoke(Offer $offer, ?array &$people = null): void
    {
        if (! in_array($offer->state, [OfferState::Open, OfferState::Gallery], true)) {
            return;
        }
        $base = $offer->published_at ?? now();
        $open = AudienceRules::openings(AudienceRules::of($offer), $people);
        // Срочное (12.10.2026) — всем сразу: задержки волн снимаются, «Не показывать» (кого нет в волнах) — как было.
        if ($offer->isUrgent()) {
            $open = array_map(fn () => 0, $open);
        }

        DB::transaction(function () use ($offer, $base, $open) {
            // Выпавшим из волны — убрать и анонс (карточку бота, «Новое предложение»).
            $gone = OfferViewer::where('offer_id', $offer->id)->whereNotIn('user_id', array_keys($open) ?: [0])->delete() > 0;
            // Уведомлённым, чья волна теперь позже, — тоже: им писать, когда она наступит.
            $later = array_keys(array_filter($open, fn ($delay) => $base->copy()->addMinutes($delay)->isFuture()));
            if ($later) {
                $gone = OfferViewer::where('offer_id', $offer->id)->whereIn('user_id', $later)->whereNotNull('notified_at')
                    ->update(['notified_at' => null]) > 0 || $gone;
            }
            if ($gone) {
                RetractOfferNotices::dispatch($offer->id);
            }
            if ($open) {
                DB::table('offer_viewers')->upsert(
                    array_map(fn ($id, $delay) => ['offer_id' => $offer->id, 'user_id' => $id, 'opens_at' => $base->copy()->addMinutes($delay)], array_keys($open), $open),
                    ['offer_id', 'user_id'], ['opens_at'],
                );
            }
        });
    }

    /** Все опубликованные — после смены групп или нового менеджера. */
    public function all(): void
    {
        $people = null;
        Offer::whereIn('state', [OfferState::Open, OfferState::Gallery])->with('vendor')->lazyById()->each(function (Offer $o) use (&$people) {
            $this($o, $people);
        });
    }
}
