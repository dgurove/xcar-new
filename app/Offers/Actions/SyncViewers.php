<?php

namespace App\Offers\Actions;

use App\Offers\AudienceRules;
use App\Offers\BidState;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Пересчитать, кто видит опубликованное предложение и с какого момента (от published_at). Вызывают публикация,
 * правка правил, смена состава групп и новый менеджер. Уже уведомлённым отметка остаётся; выпавшие из круга
 * теряют предложение сразу. Волну сдвинули на будущее (13.10.2026: «остальным через 2 дня» стало «через 5», а
 * 21 менеджеру уже пришло «Новое предложение») — это тоже «выпал»: анонс отзывается, отметка снимается, и уведомление
 * придёт, когда его волна наступит.
 *
 * **Есть подтверждение — следующие волны не открываются** (07.11.2026, владелец: «если сделали „опубликовать этим, остальным
 * через N дней“ — нельзя публиковать остальным, если хотя бы от одного есть подтверждение»): пока у машины живое
 * подтверждение того, кому она уже открыта, волн в будущем нет; остальным — только когда по всем живым отказали или их
 * отозвали: `Bid::saved` зовёт пересчёт, и волны встают по прежнему расписанию (прошедшее время — сразу, в оставшийся срок
 * приёма; приём кончился — никому, машина уходит в «Без подтверждений»).
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

        $opens = array_map(fn ($delay) => $base->copy()->addMinutes($delay), $open);
        if ($offer->state === OfferState::Open && ($held = $this->held($offer, $opens))) {
            $open = array_diff_key($open, $held);
            $opens = array_diff_key($opens, $held);
        }
        // Приём кончился — новых людей в волнах нет (07.11.2026, владелец: отказали по подтверждениям после конца приёма —
        // остальные машину не видят, она уходит в «Без подтверждений»; иначе до шага часов им ушло бы «Новое предложение»).
        if ($offer->state === OfferState::Open && $offer->bids_close_at?->isPast()) {
            $already = array_flip(OfferViewer::where('offer_id', $offer->id)->pluck('user_id')->all());
            $open = array_intersect_key($open, $already);
            $opens = array_intersect_key($opens, $already);
        }

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

    /**
     * Волны, которые держит подтверждение: у кого-то живое (`Active`, `Accepted`) — все, чья волна позже его волны, не видят,
     * наступило их время или нет (5 дней прошли, одному отказали, второе висит — всё ещё нет). Кому уже показали и написали
     * (`notified_at`), не прячем. Первым удержанием — строка истории.
     *
     * @param  array<int, CarbonInterface>  $opens
     * @return array<int, true>
     */
    private function held(Offer $offer, array $opens): array
    {
        $bidders = $offer->bids()->whereIn('state', [BidState::Active, BidState::Accepted])->pluck('user_id')->unique()
            ->filter(fn ($id) => isset($opens[$id]));
        if ($bidders->isEmpty()) {
            return [];
        }
        $wave = $bidders->map(fn ($id) => $opens[$id])->min();
        $shown = array_flip(OfferViewer::where('offer_id', $offer->id)->whereNotNull('notified_at')->where('opens_at', '<=', now())->pluck('user_id')->all());
        $held = array_keys(array_filter($opens, fn ($at, $id) => $at->gt($wave) && ! isset($shown[$id]), ARRAY_FILTER_USE_BOTH));
        if (! $held) {
            return [];
        }
        if (OfferViewer::where('offer_id', $offer->id)->whereIn('user_id', $held)->exists()) {
            $offer->log(OfferEventType::Note, null, ['text' => 'Остальным не открывается: есть подтверждение']);
        }

        return array_fill_keys($held, true);
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
