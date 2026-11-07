<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\Parallels;
use App\Users\User;

/**
 * Параллельные сделки машины закрываются (07.11.2026): главная дошла до оплаты или закрылась, машина ушла из сделки,
 * поставщик отказал, админ «Снять», менеджер «Я передумал». У менеджера — обычная отменённая сделка («Сделка
 * отменена» / «Поставщик отказал»), ни слова о другом; подтверждение — отклонено молча (`CancelDeal`). Причина `parallel` —
 * своя, чтобы отличать в базе; поставщик отказал — `supplier`, как у главной (`DeclineBySupplier::mark`).
 */
final class CloseParallels
{
    public function __construct(private CancelDeal $cancel) {}

    public function __invoke(Offer $offer, ?User $by, string $why, string $reason = 'parallel'): int
    {
        $deals = Parallels::of($offer);
        foreach ($deals as $deal) {
            $this->one($deal->setRelation('offer', $offer), $by, $why, $reason);
        }

        return $deals->count();
    }

    public function one(Deal $deal, ?User $by, string $why, string $reason = 'parallel'): void
    {
        if (! $deal->isParallel()) {
            return;
        }
        ($this->cancel)($deal, $by);
        $deal->forceFill(['close_reason' => $reason, 'shadow_stage_id' => null])->save();
        $deal->offer->log(OfferEventType::Parallel, $by, ['do' => 'closed', 'deal' => $deal->id, 'manager' => $deal->buyer_id, 'why' => $why]);
    }
}
