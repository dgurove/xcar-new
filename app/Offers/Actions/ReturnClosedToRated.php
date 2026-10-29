<?php

namespace App\Offers\Actions;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приём кончился без подтверждений — черновиком во «Завершённые → Не продано» (22.10.2026, владелец: «просто возвращал
 * с надписью „приём закрыт“, чтобы удобно либо в архив, либо снова опубликовать»; 29.10.2026 начальник — своей вкладкой,
 * не в «Оцененных»): метка `intake_closed_at` гаснет при следующей публикации. Опубликовано — значит идёт приём
 * (29.10.2026, владелец: «приём закрыт, но опубликовано — бред»): часы берут любую открытую с прошедшим сроком без живых
 * подтверждений, без окна — и ту, у которой последнее подтверждение отклонили или отозвали после срока, и пропущенную,
 * пока часы стояли. С планом «на аукцион» его забирает `SendToAuction` раньше.
 */
final class ReturnClosedToRated
{
    public function __construct(private ChangeOfferState $change) {}

    public function __invoke(): int
    {
        $n = 0;
        $offers = Offer::where('state', OfferState::Open)->whereNull('auction_planned_at')
            ->where('bids_close_at', '<=', now())->whereDoesntHave('deal')
            ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted]))->get();
        foreach ($offers as $offer) {
            try {
                // Одной транзакцией: живая строка списка (`Offer::saved` → после коммита) уже видит метку и встаёт во
                // «Завершённые», а не на миг в «Оцененные».
                DB::transaction(function () use ($offer) {
                    $closed = $offer->bids_close_at;
                    $offer = ($this->change)($offer, OfferState::Draft, null);
                    // Во вкладку пришло, когда кончился приём, — не когда часы дошли (старые закрытые встали разом).
                    DB::table('offers')->where('id', $offer->id)->update(['intake_closed_at' => $closed, 'tab_at' => $closed]);
                });
                $n++;
            } catch (Throwable $e) {
                Log::warning('offers: приём закрыт, в «Завершённые» не ушло', ['offer' => $offer->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
