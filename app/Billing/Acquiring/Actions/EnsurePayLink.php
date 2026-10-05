<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Invoice;
use App\Billing\Robot;

/**
 * Ссылку на оплату никто не «делает» (владелец 05.10.2026: «кто и как делает ссылку» — непонятно): у счёта ПРАЙМ к
 * оплате она есть всегда — заводится вместе со счётом и после частичной оплаты или отмены оплаты, на остаток, не больше
 * лимита одного платежа. Плательщик — контрагент счёта (менеджер или его покупатель); почты нет — спросит `/pay`.
 * Только счета сделок и гаража; парковка (ИП), вознаграждение, обязательства, демо и счета без шлюза — без ссылки.
 */
final class EnsurePayLink
{
    public function __construct(private CreatePayLink $create) {}

    public function __invoke(Invoice $invoice): ?PayLink
    {
        $invoice->refresh();
        if (! PayLink::eligible($invoice)) {
            return null;
        }
        if ($open = PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->first()) {
            return $open;
        }
        $amount = PayLink::defaultAmount($invoice);
        if ($amount <= 0) {
            return null;
        }
        $party = $invoice->party;

        return ($this->create)($invoice, Robot::user(), $amount, PayerKind::Other, null, $party->name, $party->phone, $party->email, auto: true);
    }
}
