<?php

namespace App\Billing\Events;

use App\Billing\Acquiring\PayLink;
use App\Billing\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** По ссылке заплатили, и оплата легла в счёт. */
final class PaidOnline
{
    use Dispatchable;

    public function __construct(public PayLink $link, public Payment $payment) {}
}
