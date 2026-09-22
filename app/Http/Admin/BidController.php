<?php

namespace App\Http\Admin;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\DeclineBid;
use App\Offers\Bid;
use App\Offers\CommissionMode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BidController
{
    /** Принять подтверждение вместе с деньгами: вознаграждение менеджеру суммой и режим. */
    public function accept(Request $request, Bid $bid, AcceptBid $accept)
    {
        $data = $request->validate([
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.$bid->amount],
            'mode' => ['nullable', Rule::enum(CommissionMode::class)],
        ]);
        $accept($bid, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, CommissionMode::tryFrom($data['mode'] ?? '') ?? CommissionMode::Payout);

        return back()->with('toast', 'Подтверждение принято, сделка открыта');
    }

    public function decline(Request $request, Bid $bid, DeclineBid $decline)
    {
        $decline($bid, $request->user());

        return back()->with('toast', 'Подтверждение отклонено');
    }
}
