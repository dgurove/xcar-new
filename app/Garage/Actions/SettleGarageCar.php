<?php

namespace App\Garage\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Billing\WorkDays;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Payer;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Расчёт по машине: счёт менеджеру на то, что он нам отдаёт, двумя строками —
 * «Транспортное средство» и «Вознаграждение менеджеру», как у сделок. Вознаграждение
 * он удерживает сам, поэтому строка тут же гасится зачётом и к оплате остаётся
 * цена продажи минус его расходы минус вознаграждение. Наши расходы в счёт не идут:
 * этих денег у менеджера не было, они просто уменьшают нашу прибыль.
 * Машину, взятую под себя, считать не с кем — расчёт закрывается без счёта.
 */
final class SettleGarageCar
{
    public function __construct(private IssueInvoice $issue, private RecordPayment $record, private InvoicePdf $pdf) {}

    public function __invoke(Car $car, User $by, bool $vat = false): ?Invoice
    {
        if ($car->sold_price === null) {
            throw ValidationException::withMessages(['car' => 'Сначала внесите итог продажи']);
        }
        if ($car->invoice_id) {
            throw ValidationException::withMessages(['car' => 'Счёт уже выставлен']);
        }

        if (! $car->manager) {
            $car->update(['settled_at' => now(), 'state' => CarState::Settled]);

            return null;
        }

        $base = round($car->sold_price - $car->spent(Payer::Manager), 2);
        $fee = (float) $car->commission;
        if ($base - $fee <= 0) {
            throw ValidationException::withMessages(['car' => 'Менеджеру нечего нам отдавать: расходы и вознаграждение съели цену']);
        }

        return DB::transaction(function () use ($car, $by, $vat, $base, $fee) {
            $offer = $car->offer;
            $lines = [['title' => 'Транспортное средство '.$offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => round($base - $fee, 2), 'kind' => ChargeKind::Sale->value]];
            if ($fee > 0) {
                $lines[] = ['title' => 'Вознаграждение менеджеру', 'qty' => 1, 'unit' => 'pc', 'price' => $fee, 'kind' => ChargeKind::AgentFee->value];
            }
            $invoice = ($this->issue)(Party::forUser($car->manager), $by, 'issued', ChargeKind::Sale, WorkDays::add(now(), 5), $vat, lines: $lines, offerId: $offer->id);
            if ($fee > 0) {
                ($this->record)($invoice, $by, $fee, null, PaymentSource::Offset, null, 'Удержано вознаграждение менеджеру');
                // PDF печётся при выставлении — перепечь с зачётом и «к оплате».
                $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
            }
            $car->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
