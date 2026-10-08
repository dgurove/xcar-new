<?php

namespace App\Billing\Actions;

use App\Billing\Accrual;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Ledger;
use App\Billing\Numbering;
use App\Billing\Party;
use App\Billing\Seller;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Выставить счёт одному контрагенту: хранение по указанный день считается
 * заново под замком (`Accrual`), отрезки этого плательщика становятся
 * строками и двигают `storage_billed_until` — пропусков не бывает, чужой
 * отрезок раньше своего отбивается; невыставленные начисления привязываются,
 * свободные строки добавляются; номер — сквозной в году у продавца; PDF — снимок в той
 * же транзакции, без файла счёта нет. `owed` — наше обязательство (номера и НДС нет, есть чужой номер).
 * Продавец — по счёту (`Seller::for`: ТС парковки — ИП, остальное — ПРАЙМ); НДС — его ставкой внутри
 * итога, а у контрагента с «НДС сверху» (`vat_on_top`) — прибавлен к строкам.
 *
 * Реестр за месяц (`$registry`: ТС, по какой день, её начисления) — один счёт плательщику без своей ТС, строки
 * хранения каждой машины со своим `vehicle_id`. Машина, которую выставить нельзя (уже выставлена, чужой отрезок),
 * пропускается своей точкой сохранения — остальные идут; пропущенные — в `$skipped`.
 */
final class IssueInvoice
{
    /** @var list<string> ТС реестра, которые не вошли, с причиной */
    public array $skipped = [];

    public function __construct(private InvoicePdf $pdf) {}

    /**
     * @param  ?CarbonInterface  $dueAt  null — срок ещё не начался (подбор до шага оплаты, `SyncDealInvoices::dueFor`)
     * @param  ?CarbonInterface  $storageUntil  хранение по этот день включительно (null — без хранения)
     * @param  list<int>  $chargeIds  невыставленные начисления
     * @param  list<array{title: string, qty: float, unit: string, price: float, kind?: string}>  $lines  свободные строки
     * @param  list<array{vehicle: Vehicle, until: CarbonInterface, charges: list<int>}>  $registry  ТС реестра за `$month`
     */
    public function __invoke(Party $party, User $by, string $direction, ChargeKind $kind, ?CarbonInterface $dueAt, ?CarbonInterface $storageUntil = null, array $chargeIds = [], array $lines = [],
        ?Vehicle $vehicle = null, ?int $dealId = null, ?int $offerId = null, ?string $externalNo = null, ?string $notes = null, array $registry = [], ?CarbonInterface $month = null): Invoice
    {
        Nav::forgetStaffCounts();
        $this->skipped = [];
        $invoice = DB::transaction(function () use ($party, $by, $direction, $kind, $dueAt, $storageUntil, $chargeIds, $lines, $vehicle, $dealId, $offerId, $externalNo, $notes, $registry, $month) {
            $year = (int) now()->format('Y');
            $issued = $direction === 'issued';
            // Реестр — только парковка: без ТС `Seller::for` отдал бы ПРАЙМ.
            $seller = $registry ? Seller::Park : Seller::for($vehicle);
            $vatRate = $issued ? ($seller->vatRate() ?: null) : null;
            $vatOnTop = $vatRate && $party->vat_on_top;
            $invoice = Invoice::create([
                'seller' => $seller, 'direction' => $direction, 'year' => $issued ? $year : null, 'number' => $issued ? Numbering::next($seller, $year) : null,
                'external_no' => $externalNo, 'kind' => $kind, 'month' => $registry && $month ? Carbon::instance($month)->startOfMonth()->toDateString() : null, 'party_id' => $party->id, 'vehicle_id' => $vehicle?->id, 'deal_id' => $dealId, 'offer_id' => $offerId,
                'issued_at' => now()->toDateString(), 'due_at' => $dueAt ? Carbon::instance($dueAt)->toDateString() : null, 'vat' => (bool) $vatRate, 'vat_rate' => $vatRate, 'vat_on_top' => $vatOnTop, 'state' => InvoiceState::Issued, 'notes' => $notes, 'created_by' => $by->id,
            ]);
            $total = 0.0;
            if ($vehicle && $storageUntil) {
                $total += $this->storage($invoice, $party, $vehicle, $storageUntil, $by);
            }
            // Реестр: машины по порядку id (замки берутся в одном порядке), каждая — своей точкой сохранения.
            $amounts = [];
            foreach (collect($registry)->sortBy(fn ($r) => $r['vehicle']->id) as $r) {
                $v = $r['vehicle'];
                try {
                    $amounts[$v->id] = DB::transaction(function () use ($invoice, $party, $v, $r, $by) {
                        $sum = $this->storage($invoice, $party, $v, $r['until'], $by);
                        $charges = Charge::whereIn('id', $r['charges'] ?? [])->where('vehicle_id', $v->id)->whereNull('invoice_id')->whereNull('voided_at')->where('party_id', $party->id)->lockForUpdate()->get();
                        $charges->each->update(['invoice_id' => $invoice->id]);

                        return $sum + (float) $charges->sum('amount');
                    });
                    $total += $amounts[$v->id];
                } catch (ValidationException $e) {
                    $this->skipped[] = $v->titleWithYear().($v->ref ? ' '.$v->ref : '').': '.implode(', ', $e->validator->errors()->all());
                }
            }
            if ($chargeIds) {
                $charges = Charge::whereIn('id', $chargeIds)->whereNull('invoice_id')->whereNull('voided_at')->where('party_id', $party->id)->lockForUpdate()->get();
                $charges->each->update(['invoice_id' => $invoice->id]);
                $total += $charges->sum('amount');
            }
            foreach ($lines as $line) {
                if (($line['title'] ?? '') === '' || ! isset($line['price'])) {
                    continue;
                }
                $charge = Charge::create([
                    'party_id' => $party->id, 'vehicle_id' => $vehicle?->id, 'deal_id' => $dealId, 'invoice_id' => $invoice->id,
                    'kind' => ChargeKind::tryFrom($line['kind'] ?? '') ?? $kind, 'title' => $line['title'], 'qty' => $line['qty'] ?? 1, 'unit' => $line['unit'] ?? 'pc',
                    'price' => $line['price'], 'amount' => round(($line['qty'] ?? 1) * $line['price'], 2), 'created_by' => $by->id,
                ]);
                $total += $charge->amount;
            }
            if ($total <= 0) {
                throw ValidationException::withMessages(['total' => 'В счёте нет ни одной строки']);
            }
            if ($vatOnTop) {
                $total += round($total * $vatRate / 100, 2);
            }
            $invoice->update(['total' => round($total, 2)]);
            $vehicle?->log($direction === 'issued' ? EventType::Invoiced : EventType::Owed, $by, ['label' => $invoice->label(), 'amount' => $invoice->total, 'party' => $party->name]);
            foreach ($amounts as $id => $amount) {
                Vehicle::find($id)?->log(EventType::Invoiced, $by, ['label' => 'реестр '.$invoice->label(), 'amount' => round($amount, 2), 'party' => $party->name]);
            }
            if ($issued) {
                $this->pdf->attach($invoice->fresh(['charges', 'party', 'vehicle.brand', 'vehicle.model']));
            }

            return $invoice;
        });
        // Число «выставить» у закрытия месяца считается дорого — сбрасываем только счетами парковки, не сделками CRM.
        if ($invoice->seller === Seller::Park) {
            Closing::forgetPending();
        }
        InvoiceIssued::dispatch($invoice, $by);

        return $invoice;
    }

    /**
     * Хранение одной ТС по день — строками счёта: отрезки этого плательщика, `storage_billed_until` двигается; уже
     * выставленное и чужой отрезок раньше своего — отказ. Возвращает сумму строк.
     */
    private function storage(Invoice $invoice, Party $party, Vehicle $vehicle, CarbonInterface $storageUntil, User $by): float
    {
        $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
        $until = Carbon::instance($storageUntil)->startOfDay();
        if ($vehicle->storage_billed_until && $until->lte($vehicle->storage_billed_until)) {
            throw ValidationException::withMessages(['storage_until' => 'Хранение по '.$vehicle->storage_billed_until->format('d.m.Y').' уже выставлено']);
        }
        $segments = Accrual::storage($vehicle, $until);
        $total = 0.0;
        foreach ($segments as $s) {
            if ($s['amount'] <= 0) {
                continue;
            }
            $payer = Ledger::payerParty($vehicle, $s['payer']);
            if ($payer?->id !== $party->id) {
                throw ValidationException::withMessages(['storage_until' => 'Хранение '.$s['from']->format('d.m').' – '.$s['to']->format('d.m').' платит '.($payer?->name ?? Accrual::payerLabel($s['payer'])).' — сначала счёт ему']);
            }
            $charge = Charge::create([
                'party_id' => $party->id, 'vehicle_id' => $vehicle->id, 'invoice_id' => $invoice->id, 'kind' => ChargeKind::Storage,
                'title' => 'Хранение '.$s['from']->format('d.m.Y').' – '.$s['to']->format('d.m.Y'), 'qty' => $s['days'], 'unit' => 'day', 'price' => $s['rate'],
                'amount' => $s['amount'], 'period_from' => $s['from'], 'period_to' => $s['to'], 'created_by' => $by->id,
            ]);
            $total += $charge->amount;
        }
        $vehicle->update(['storage_billed_until' => $segments->isEmpty() ? $vehicle->storage_billed_until : $until]);

        return $total;
    }
}
