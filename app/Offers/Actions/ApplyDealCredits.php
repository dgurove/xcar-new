<?php

namespace App\Offers\Actions;

use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Offers\Deal;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Деньги разовых ссылок с машиной сделки (`Deal::credits`) — в её счета зачётом (09.10.2026, владелец: «пусть это будет
 * зачёт суммы сделки, а когда уже оплачено то, что в сделке обозначено, — это просто дополнительная доплата»). Счёт
 * сделки остаётся на свою сумму, в него ложится оплата `PaymentSource::Credit` с `credit_invoice_id` ручного счёта —
 * дальше всё штатно: остаток, ссылка сделки на остаток (`EnsurePayLink`), оплачен целиком — шаг оплаты и выплата
 * (`AdvanceOnPayment`, `PayoutWhenPaid`). Класть некуда — доплата сверх сделки (`Deal::creditExtra`).
 * Зачёт ручного счёта, который аннулировали, вернули или отвязали от сделки, гасится (`release`).
 */
final class ApplyDealCredits
{
    public function __construct(private RecordPayment $record, private VoidPayment $void) {}

    public function __invoke(Deal $deal, User $by): void
    {
        DB::transaction(function () use ($deal, $by) {
            Deal::whereKey($deal->id)->lockForUpdate()->first();
            $this->release($deal, $by);
            $credits = $deal->credits()->where('paid', '>', 0)->orderBy('id')->get();
            if ($credits->isEmpty()) {
                return;
            }
            // Сначала подбор (его менеджер платит по ссылке), потом счёт ПРАЙМ; вознаграждение от вендора и выплаты — не сюда.
            $targets = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', InvoiceState::Issued)
                ->where('kind', '!=', ChargeKind::Reward)->orderByRaw("kind = 'selection' desc")->orderBy('id')->get();
            foreach ($credits as $credit) {
                $left = round((float) $credit->paid - $this->applied($credit), 2);
                foreach ($targets as $target) {
                    if ($left <= 0) {
                        break;
                    }
                    $room = round($target->fresh()->remaining() - $target->claimed(fresh: true), 2);
                    if ($room <= 0) {
                        continue;
                    }
                    $amount = min($left, $room);
                    $payment = ($this->record)($target->fresh(), $by, $amount, null, PaymentSource::Credit, null, 'Оплата по ссылке, счёт '.$credit->label());
                    $payment->update(['credit_invoice_id' => $credit->id]);
                    $left = round($left - $amount, 2);
                }
            }
        });
    }

    /** Зачтено по ручному счёту — живые оплаты-зачёты с ним. */
    public function applied(Invoice $credit): float
    {
        return round((float) Payment::where('credit_invoice_id', $credit->id)->where('source', PaymentSource::Credit)
            ->whereNull('voided_at')->where('state', PaymentState::Confirmed)->sum('amount'), 2);
    }

    /**
     * Зачёты, которым больше не на чем стоять: ручной счёт аннулирован, отвязан от этой сделки или по нему вернули деньги
     * (зачтено больше, чем на нём сейчас денег). Гасим их и кладём заново.
     */
    private function release(Deal $deal, User $by): void
    {
        $payments = Payment::where('source', PaymentSource::Credit)->whereNull('voided_at')->whereNotNull('credit_invoice_id')
            ->whereIn('invoice_id', Invoice::where('deal_id', $deal->id)->select('id'))->with('invoice')->get();
        foreach ($payments->groupBy('credit_invoice_id') as $creditId => $rows) {
            $credit = Invoice::find($creditId);
            $stale = ! $credit || $credit->state === InvoiceState::Void || $credit->credit_deal_id !== $deal->id
                || $rows->sum('amount') > (float) $credit->paid + 0.005;
            if ($stale) {
                $rows->each(fn (Payment $p) => ($this->void)($p, $by, 'Зачёт по ссылке снят'));
            }
        }
    }
}
