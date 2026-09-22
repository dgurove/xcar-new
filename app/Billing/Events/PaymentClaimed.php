<?php

namespace App\Billing\Events;

use App\Billing\Payment;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentClaimed
{
    use Dispatchable;

    public function __construct(public Payment $payment, public ?User $by = null) {}
}
