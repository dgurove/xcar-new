<?php

namespace App\Http\Admin;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\DeclineBid;
use App\Offers\Bid;
use Illuminate\Http\Request;

class BidController
{
    public function accept(Request $request, Bid $bid, AcceptBid $accept)
    {
        $accept($bid, $request->user());

        return back()->with('toast', 'Ставка принята, сделка открыта');
    }

    public function decline(Request $request, Bid $bid, DeclineBid $decline)
    {
        $decline($bid, $request->user());

        return back()->with('toast', 'Ставка отклонена');
    }
}
