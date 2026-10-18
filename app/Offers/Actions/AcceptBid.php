<?php

namespace App\Offers\Actions;

use App\Garage\GaragePayer;
use App\Offers\Bid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Giving;
use App\Users\User;

/**
 * Принять подтверждение — «Отдать» (`Give`) с готовым подтверждением: кому, куда и цена — из него. Вывоз не трогает
 * (его выбирают в шторке, `GiveController`).
 */
final class AcceptBid
{
    public function __construct(private Give $give) {}

    /** @param  bool  $loss  согласие принять не выше закупочной (переспросили в шторке) */
    public function __invoke(Bid $bid, User $by, ?int $commission = null, CommissionMode $mode = CommissionMode::Payout, ?GaragePayer $payer = null, DealScheme $scheme = DealScheme::Prime, ?int $ownerPrice = null, ?int $share = null, bool $loss = false): Deal
    {
        return ($this->give)(Giving::bid($bid, scheme: $scheme, ownerPrice: $ownerPrice, commission: $commission, mode: $mode, payer: $payer, share: $share, loss: $loss), $by);
    }
}
