<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use App\Workflow\Requirement;

/**
 * Сделка сорвалась: закрыта, подтверждение победителя отклонено, просьбы к нему сняты,
 * невыплаченное вознаграждение гаснет (выплаченное остаётся историей), ждущая машина гаражной уходит из гаража.
 */
final class CancelDeal
{
    public function __invoke(Deal $deal, ?User $by = null): Deal
    {
        $deal->update(['state' => DealState::Cancelled, 'closed_at' => now()]);
        if ($deal->bid?->state === BidState::Accepted) {
            Bid::whereKey($deal->bid_id)->update(['state' => BidState::Declined]);
        }
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
        // Гаражная сорвалась, пока ждала страховую («Отказываюсь», отказ поставщика, отдали другому) — из гаража долой.
        if ($deal->isGarage() && ($car = GarageCar::where('deal_id', $deal->id)->where('state', CarState::Waiting)->first())) {
            $car->delete();
            GarageChanged::dispatch($car);
        }
        if (($fee = $deal->agentFee()->first()) && $fee->state === InvoiceState::Issued && $fee->paid == 0) {
            app(VoidInvoice::class)($fee, $by, 'Сделка отменена');
        }

        return $deal;
    }
}
