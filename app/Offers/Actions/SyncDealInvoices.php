<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Счета сделки ставятся сами по её схеме (05.10.2026, владелец: «что такое "Выставите счёт"?» — у каждой схемы известно,
 * кому и какой счёт). Два места:
 * — подбор (`Selection`) менеджеру со ссылкой: у ДКП и «страховой напрямую» — цена − собственнику/страховой −
 *   вознаграждение (850 − 750 − 20 = 80 000) одной строкой; у гаражной «платит менеджер» — наша доля;
 * — ПРАЙМ (`Sale`) без ссылки: «Транспортное средство» = закупочная и «Агентское вознаграждение» = разница (944 000 +
 *   106 000) тому, кого менеджер указал плательщиком; платит менеджер с «удерживает сам» — его вознаграждение зачётом,
 *   платит покупатель — вознаграждение выплачиваем; гаражной «платит менеджер» у ПРАЙМ — менеджеру на закупочную.
 * Счёт, что уже стоит как надо, не трогаем; другой, за который не платили, — аннулируем и ставим заново; оплаченный —
 * история, его не меняем. Не нужен схеме — не трогаем вовсе.
 */
final class SyncDealInvoices
{
    private const DAYS = 3;

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private RecordPayment $record, private InvoicePdf $pdf) {}

    public function __invoke(Deal $deal, User $by): void
    {
        $deal->loadMissing(['offer', 'buyer', 'contract.buyer']);
        DB::transaction(function () use ($deal, $by) {
            $this->slot($deal, $by, ChargeKind::Selection, $this->selection($deal));
            $this->slot($deal, $by, ChargeKind::Sale, $this->prime($deal));
        });
    }

    /** @return array{party: Party, lines: list<array>, offset: float}|null */
    private function selection(Deal $deal): ?array
    {
        $due = match (true) {
            $deal->isGarageManager() => (int) $deal->share,
            $deal->paysSelection() => (int) $deal->selectionBase() - (int) $deal->commission,
            default => 0,
        };
        if ($due <= 0 || ! $deal->buyer) {
            return null;
        }

        return ['party' => Party::forUser($deal->buyer), 'offset' => 0.0,
            'lines' => [['title' => 'Подбор ТС '.$deal->offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => (float) $due, 'kind' => ChargeKind::Selection->value]]];
    }

    /** @return array{party: Party, lines: list<array>, offset: float}|null */
    private function prime(Deal $deal): ?array
    {
        if (! $deal->isPrime() || $deal->cost === null || ! $deal->buyer) {
            return null;
        }
        $car = 'Транспортное средство '.$deal->offer->titleWithYear();
        if ($deal->isGarage()) {
            return $deal->isGarageManager()
                ? ['party' => Party::forUser($deal->buyer), 'offset' => 0.0, 'lines' => [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->cost, 'kind' => ChargeKind::Sale->value]]]
                : null;
        }
        $contract = $deal->contract;
        if (! $contract?->buyer_user_id || $deal->amount === null) {
            return null;
        }
        $managerPays = $contract->payer === 'manager' || $contract->buyer_user_id === $deal->buyer_id;
        // Платит покупатель — менеджеру удерживать не из чего: вознаграждение выплачиваем после оплаты.
        if (! $managerPays && $deal->withholds()) {
            $deal->update(['commission_mode' => CommissionMode::Payout]);
        }
        $party = Party::forUser($managerPays ? $deal->buyer : $contract->buyer);
        $fee = max(0, (int) $deal->amount - (int) $deal->cost);
        $lines = [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->cost, 'kind' => ChargeKind::Sale->value]];
        if ($fee > 0) {
            $lines[] = ['title' => 'Агентское вознаграждение', 'qty' => 1, 'unit' => 'pc', 'price' => (float) $fee, 'kind' => ChargeKind::AgentFee->value];
        }

        return ['party' => $party, 'lines' => $lines, 'offset' => $managerPays && $deal->withholds() ? (float) min((int) $deal->commission, $fee) : 0.0];
    }

    private function slot(Deal $deal, User $by, ChargeKind $kind, ?array $want): void
    {
        // Схеме этот счёт сейчас не нужен — что выставили руками («Ещё счёт»), не трогаем; при смене схемы прежний
        // гасит `UpdateDealMoney`.
        if (! $want) {
            return;
        }
        $current = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', $kind)->where('state', '!=', InvoiceState::Void)
            ->with(['payments', 'claims', 'charges'])->get();
        $total = $want ? round(array_sum(array_map(fn ($l) => $l['price'] * $l['qty'], $want['lines'])), 2) : null;
        // Сверяем строки, а не итог: у плательщика с «НДС сверху» итог больше строк.
        $fits = fn (Invoice $i) => $want && $i->party_id === $want['party']->id && abs((float) $i->charges->whereNull('voided_at')->sum(fn ($c) => $c->price * $c->qty) - $total) < 0.01
            && abs($i->payments->where('source', PaymentSource::Offset)->sum('amount') - $want['offset']) < 0.01;
        $paid = fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => $p->source !== PaymentSource::Offset);

        $keep = $current->first(fn (Invoice $i) => $fits($i) || $paid($i));
        foreach ($current as $invoice) {
            if ($invoice->isNot($keep) && ! $paid($invoice)) {
                ($this->void)($invoice, $by, 'Деньги сделки изменены');
            }
        }
        if ($keep) {
            return;
        }
        $invoice = ($this->issue)($want['party'], $by, 'issued', $kind, now()->addDays(self::DAYS), lines: $want['lines'], dealId: $deal->id, offerId: $deal->offer_id);
        if ($want['offset'] > 0) {
            ($this->record)($invoice, $by, $want['offset'], null, PaymentSource::Offset, null, 'Удержано агентское вознаграждение');
            $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
        }
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).' — '.$want['party']->name]);
    }
}
