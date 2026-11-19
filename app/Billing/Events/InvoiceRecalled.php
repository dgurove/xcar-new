<?php

namespace App\Billing\Events;

use App\Billing\Invoice;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Отправленный счёт сделки отозвали (`Offers\Actions\ShowToManager::recallInvoice`, 19.11.2026, владелец: «отозвать счёт, чтобы
 * поправить и заново отправить»): менеджер его больше не видит, на шаге оплаты ход снова наш (`PassTurnOnInvoice`).
 */
final class InvoiceRecalled
{
    use Dispatchable;

    public function __construct(public Invoice $invoice, public ?User $by = null) {}
}
