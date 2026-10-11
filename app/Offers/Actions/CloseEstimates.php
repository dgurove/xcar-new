<?php

namespace App\Offers\Actions;

use App\Media\Jobs\StampPhotos;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimatesClosed;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Nav;
use App\Users\User;

/**
 * Оценка больше не нужна: цена продажи поставлена (своя админа, «Взять», «Из текста»), черновик ушёл дальше или админ
 * снял просьбу. Открытые — `closed`; последняя закрыта, а запрет шеринга ставила оценка — запрет снимается, знак тоже.
 * Уведомления «Оцените» в ленте и Telegram стираются (`EstimatesClosed` → `RetractEstimateNotices`).
 *
 * @param  list<int>|null  $only  снять только эти просьбы (крестик в окошке); null — все открытые
 */
final class CloseEstimates
{
    /** @param  bool  $keep  «Оставить свою» у корректировки: цена админа остаётся, просьбы закрываются (в истории — словами) */
    public function __invoke(Offer $offer, ?User $by = null, ?array $only = null, bool $keep = false): void
    {
        $open = $offer->estimates()->open()->when($only !== null, fn ($q) => $q->whereIn('id', $only))->get(['id', 'user_id']);
        if ($open->isNotEmpty()) {
            Estimate::whereIn('id', $open->pluck('id'))->update(['state' => EstimateState::Closed, 'updated_at' => now()]);
            if ($keep) {
                $offer->log(OfferEventType::Estimate, $by, ['do' => 'kept', 'amount' => $offer->asking_price]);
            } elseif ($only !== null) {
                $offer->log(OfferEventType::Estimate, $by, ['do' => 'closed', 'users' => $open->pluck('user_id')->all()]);
            }
        }
        $offer->refresh();
        if ($offer->share_locked_by_estimate && ! $offer->estimates()->open()->exists()) {
            $offer->forceFill(['share_locked' => false, 'share_locked_by_estimate' => false])->save();
            StampPhotos::dispatch($offer);
        }
        Nav::forgetStaffCounts();
        if ($open->isNotEmpty()) {
            EstimatesClosed::dispatch($offer, $open->pluck('id')->all(), $open->pluck('user_id')->unique()->values()->all());
        }
    }
}
