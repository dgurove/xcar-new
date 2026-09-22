<?php

namespace App\Billing\Events;

use App\Billing\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/** Все счета по сделке оплачены — вознаграждение менеджера стало обязательством. */
final class AgentFeeDue
{
    use Dispatchable;

    public function __construct(public Invoice $fee) {}
}
