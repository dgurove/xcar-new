<?php

namespace App\Billing\Events;

use App\Billing\Invoice;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceOverdue
{
    use Dispatchable;

    public function __construct(public Invoice $invoice, public ?User $by = null) {}
}
