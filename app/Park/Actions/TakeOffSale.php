<?php

namespace App\Park\Actions;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\UnlistParkOffer;
use App\Offers\BidState;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «Убрать из продажи» на парковке (владелец 05.10.2026: «как это отменить — должно быть на парковке, удобно»). Черновик,
 * ни разу не выходивший наружу, без подтверждений, — удаляется (`UnlistParkOffer`); опубликованное или бывшее в продаже —
 * в архив (`ChangeOfferState`: ждущие подтверждения отклоняются). ТС отвязывает `Park\Sale::end` — кадры и документы
 * остаются у неё. Сделку так не снять: её отменяют в CRM.
 */
final class TakeOffSale
{
    public function __construct(private UnlistParkOffer $unlist, private ChangeOfferState $change) {}

    public function __invoke(Vehicle $vehicle, User $by): void
    {
        $offer = $vehicle->offer;
        if (! $offer) {
            return;
        }
        if (! self::allowed($offer->state, (bool) $offer->deal)) {
            throw ValidationException::withMessages(['sale' => 'Идёт сделка — снять с продажи можно, только отменив её в CRM']);
        }
        $fresh = $offer->state === OfferState::Draft && $offer->published_at === null && ! $offer->bids()->where('state', BidState::Active)->exists();
        $fresh ? ($this->unlist)($offer, $by) : ($this->change)($offer, OfferState::Archived, $by);
    }

    /** Можно ли убрать: нет сделки и у состояния есть переход в архив (у сделки и гаража его нет). */
    public static function allowed(OfferState $state, bool $deal): bool
    {
        return ! $deal && $state->allows(OfferState::Archived);
    }
}
