<?php

namespace App\Offers\Actions;

use App\Offers\AudienceRules;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use Illuminate\Support\Facades\DB;

/**
 * Пересчитать, кто видит опубликованное предложение и с какого момента (от published_at). Вызывают публикация,
 * правка правил, смена состава групп и новый менеджер. Уже уведомлённым отметка остаётся; выпавшие из круга
 * теряют предложение сразу.
 */
final class SyncViewers
{
    public function __invoke(Offer $offer): void
    {
        if (! in_array($offer->state, [OfferState::Open, OfferState::Gallery], true)) {
            return;
        }
        $base = $offer->published_at ?? now();
        $open = AudienceRules::openings(AudienceRules::of($offer));

        DB::transaction(function () use ($offer, $base, $open) {
            OfferViewer::where('offer_id', $offer->id)->whereNotIn('user_id', array_keys($open) ?: [0])->delete();
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
        Offer::whereIn('state', [OfferState::Open, OfferState::Gallery])->lazyById()->each(fn (Offer $o) => $this($o));
    }
}
