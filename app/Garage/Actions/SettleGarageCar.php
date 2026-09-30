<?php

namespace App\Garage\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Billing\Vat;
use App\Billing\WorkDays;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Garage\Settlement;
use App\Notifications\MoneyNotice;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Расчёт по машине. Менеджер должен нам — счёт двумя строками: «Транспортное средство» и
 * «Вознаграждение менеджеру», которое он удерживает сам (строка тут же гасится зачётом),
 * к оплате — цена продажи минус его расходы минус вознаграждение. Продали в минус —
 * наоборот, обязательство перед ним на разницу (`owed`, попадёт в его «Вам к выплате»).
 * Ноль или машина, взятая под себя, — расчёт закрывается без документа.
 */
final class SettleGarageCar
{
    public function __construct(private IssueInvoice $issue, private RecordPayment $record, private InvoicePdf $pdf) {}

    public function __invoke(Car $car, User $by, ?int $commission = null): ?Invoice
    {
        if ($car->sold_price === null) {
            throw ValidationException::withMessages(['car' => 'Сначала внесите итог продажи']);
        }
        if ($car->invoice_id) {
            throw ValidationException::withMessages(['car' => 'Счёт уже выставлен']);
        }

        if ($car->manager) {
            $car->update(['commission' => $commission]);
        }
        $due = Settlement::of($car)['due'];
        if (! $car->manager || abs((float) $due) < 0.005) {
            $car->update(['settled_at' => now(), 'state' => CarState::Settled]);
            GarageChanged::dispatch($car);

            return null;
        }

        $invoice = DB::transaction(function () use ($car, $by, $due) {
            $offer = $car->offer;
            $party = Party::forUser($car->manager);
            if ($due < 0) {
                $invoice = ($this->issue)($party, $by, 'owed', ChargeKind::AgentFee, WorkDays::add(now(), 5), false,
                    lines: [['title' => 'Расходы и вознаграждение по '.$offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => round(-$due, 2), 'kind' => ChargeKind::AgentFee->value]], offerId: $offer->id);
            } else {
                $fee = (float) $car->commission;
                $lines = [['title' => 'Транспортное средство '.$offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => round($due, 2), 'kind' => ChargeKind::Sale->value]];
                if ($fee > 0) {
                    $lines[] = ['title' => 'Вознаграждение менеджеру', 'qty' => 1, 'unit' => 'pc', 'price' => $fee, 'kind' => ChargeKind::AgentFee->value];
                }
                $invoice = ($this->issue)($party, $by, 'issued', ChargeKind::Sale, WorkDays::add(now(), 5), false, lines: $lines, offerId: $offer->id,
                    vatRate: Vat::rate(), vatOnTop: (bool) $party->vat_on_top);
                if ($fee > 0) {
                    ($this->record)($invoice, $by, $fee, null, PaymentSource::Offset, null, 'Удержано вознаграждение менеджеру');
                    // PDF печётся при выставлении — перепечь с зачётом и «к оплате».
                    $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
                }
            }
            $car->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        GarageChanged::dispatch($car);
        $car->manager->notify(MoneyNotice::garageInvoice($invoice->fresh(), $car));

        return $invoice;
    }
}
