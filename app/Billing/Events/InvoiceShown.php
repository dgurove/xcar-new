<?php

namespace App\Billing\Events;

use App\Billing\Invoice;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Счёт сделки отправлен менеджеру кнопкой в CRM (`Offers\Actions\ShowToManager`, 16.11.2026): для менеджера это и есть «счёт
 * выставлен» — уведомление, ссылка, ход на шаге оплаты. Счёт без сделки этого события не ждёт: ему хватает `InvoiceIssued`.
 */
final class InvoiceShown
{
    use Dispatchable;

    public function __construct(public Invoice $invoice, public ?User $by = null) {}
}
