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
        $amount = PayLink::defaultAmount($invoice);
        if ($open = PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->first()) {
            // Своя ссылка, которую ещё не открывали, — на текущий остаток: зачёт удержанного вознаграждения ложится после
            // выставления, и ссылка оставалась на всю сумму счёта (Kuga, 100 000 вместо 80 000).
            if ((int) $open->created_by === Robot::user()->id && $amount > 0 && abs((float) $open->amount - $amount) >= 0.01 && ! $open->attempts()->exists()) {
                $open->update(['amount' => $amount]);
            }

            return $open;
        }
        if ($amount <= 0) {
            return null;
        }
        // Без плательщика (18.11.2026, владелец: «плательщика ваще вырезаем… по ссылке просто почта»): почту для чека вводит тот, кто
        // платит, на `/pay` — почту менеджера туда не подставляем.
        // Разовая услуга из CRM — почта плательщика, вписанная при выставлении (`IssueServiceInvoice`); сторона — сам менеджер (ссылка без
        // плательщика, 22.11.2026) — его почту не подставляем.
        return ($this->create)($invoice, Robot::user(), $amount, PayerKind::Other, email: $invoice->isService() && ! \App\Users\User::where('party_id', $invoice->party_id)->exists() ? $invoice->party->email : null, auto: true);
    }
}
