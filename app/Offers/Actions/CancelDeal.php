<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use App\Workflow\Requirement;

/**
 * Сделка сорвалась: закрыта, подтверждение победителя отклонено, просьбы к нему сняты,
 * невыплаченное вознаграждение гаснет (выплаченное остаётся историей).
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
        if (($fee = $deal->agentFee()->first()) && $fee->state === InvoiceState::Issued && $fee->paid == 0) {
            app(VoidInvoice::class)($fee, $by, 'Сделка отменена');
        }

        return $deal;
    }
}
