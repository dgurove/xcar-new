<?php

namespace App\Offers\Actions;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приём кончился без подтверждений — назад в «Оцененные» (22.10.2026, владелец: «я бы их просто возвращал в „Оцененные“ с
 * надписью „приём закрыт“, чтобы удобно либо в архив, либо снова опубликовать»): черновиком с ценами, метка
 * `intake_closed_at` гаснет при следующей публикации. Часы (`offers:tick`) берут закрывшиеся за последние 15 минут: прежние
 * 173 «Приёма закрыт» на 22.10.2026 так и стоят в своей вкладке. С планом «на аукцион» его забирает `SendToAuction` раньше.
 */
final class ReturnClosedToRated
{
    private const WINDOW_MINUTES = 15;

    public function __construct(private ChangeOfferState $change) {}

    public function __invoke(): int
    {
        $n = 0;
        $offers = Offer::where('state', OfferState::Open)->whereNull('auction_planned_at')
            ->whereBetween('bids_close_at', [now()->subMinutes(self::WINDOW_MINUTES), now()])
            ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted]))->get();
        foreach ($offers as $offer) {
            try {
                $closed = $offer->bids_close_at;
                $offer = ($this->change)($offer, OfferState::Draft, null);
                DB::table('offers')->where('id', $offer->id)->update(['intake_closed_at' => $closed]);
                $n++;
            } catch (Throwable $e) {
                Log::warning('offers: приём закрыт, в «Оцененные» не ушло', ['offer' => $offer->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
