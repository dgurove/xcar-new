<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Счёт по сделке двумя строками: «Транспортное средство» (или «Подбор ТС») на базу
 * за вычетом вознаграждения и «Агентское вознаграждение» — итого равен базе.
 * Менеджер удерживает вознаграждение сам — его строка тут же гасится зачётом,
 * к оплате остаётся база минус вознаграждение. Вознаграждение от поставщика —
 * одна строка вендору. С этого счёта менеджер видит своё вознаграждение.
 */
final class IssueDealInvoice
{
    public function __construct(private IssueInvoice $issue, private RecordPayment $record, private InvoicePdf $pdf) {}

    public function __invoke(Deal $deal, User $by, Party $party, ChargeKind $kind, float $base, CarbonInterface $dueAt, bool $vat, ?string $notes = null, ?string $title = null): Invoice
    {
        $offer = $deal->offer;
        $car = $offer->titleWithYear();
        $fee = $kind === ChargeKind::Reward ? 0 : (int) $deal->commission;
        $title ??= match ($kind) {
            ChargeKind::Sale => 'Транспортное средство '.$car,
            ChargeKind::Selection => 'Подбор ТС '.$car,
            ChargeKind::Reward => 'Вознаграждение по продаже '.$car,
            default => $car,
        };
        $lines = [['title' => $title, 'qty' => 1, 'unit' => 'pc', 'price' => round($base - $fee, 2), 'kind' => $kind->value]];
        if ($fee > 0) {
            $lines[] = ['title' => 'Агентское вознаграждение', 'qty' => 1, 'unit' => 'pc', 'price' => (float) $fee, 'kind' => ChargeKind::AgentFee->value];
        }

        return DB::transaction(function () use ($deal, $by, $party, $kind, $dueAt, $vat, $notes, $lines, $fee, $offer) {
            $invoice = ($this->issue)($party, $by, 'issued', $kind, $dueAt, $vat, lines: $lines, dealId: $deal->id, offerId: $offer->id, notes: $notes);
            if ($fee > 0 && $deal->withholds()) {
                ($this->record)($invoice, $by, (float) $fee, null, PaymentSource::Offset, null, 'Удержано агентское вознаграждение');
                // PDF печётся при выставлении — перепечь с зачётом и «к оплате».
                $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
            }
            $offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).' — '.$party->name]);

            return $invoice;
        });
    }
}
