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

        // Регионы менеджера (22.10.2026, «убрать совсем»): машина не из его регионов — он не в волнах, и его покупатели тоже.
        // Регион машины не известен — показываем всем.
        if ($offer->region_id) {
            $people['regions'] ??= DB::table('users')->whereNotNull('offer_regions')->pluck('offer_regions', 'id')
                ->map(fn ($r) => array_map('intval', json_decode((string) $r, true) ?: []))->filter()->all();
            $open = array_filter($open, fn ($delay, $id) => ! isset($people['regions'][$id]) || in_array($offer->region_id, $people['regions'][$id], true), ARRAY_FILTER_USE_BOTH);
        }

        $opens = array_map(fn ($delay) => $base->copy()->addMinutes($delay), $open);

        DB::transaction(function () use ($offer, $open, $opens) {
            // Выпавшим из волны — убрать и анонс (карточку бота, «Новое предложение»).
            $gone = OfferViewer::where('offer_id', $offer->id)->whereNotIn('user_id', array_keys($open) ?: [0])->delete() > 0;
            // Уведомлённым, чья волна теперь позже, — тоже: им писать, когда она наступит.
            $later = array_keys(array_filter($opens, fn ($at) => $at->isFuture()));
            if ($later) {
                $gone = OfferViewer::where('offer_id', $offer->id)->whereIn('user_id', $later)->whereNotNull('notified_at')
                    ->update(['notified_at' => null]) > 0 || $gone;
            }
            if ($gone) {
                RetractOfferNotices::dispatch($offer->id);
            }
            if ($open) {
                DB::table('offer_viewers')->upsert(
                    array_map(fn ($id, $at) => ['offer_id' => $offer->id, 'user_id' => $id, 'opens_at' => $at], array_keys($opens), $opens),
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
