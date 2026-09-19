<?php

namespace App\Billing\Actions;

use App\Billing\Accrual;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Ledger;
use App\Billing\Numbering;
use App\Billing\Party;
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
 * свободные строки добавляются; номер — сквозной в году; PDF — снимок в той
 * же транзакции, без файла счёта нет. `owed` — наше обязательство (номера нет, есть чужой).
 */
final class IssueInvoice
{
    public function __construct(private InvoicePdf $pdf) {}

    /**
     * @param  ?CarbonInterface  $storageUntil  хранение по этот день включительно (null — без хранения)
     * @param  list<int>  $chargeIds  невыставленные начисления
     * @param  list<array{title: string, qty: float, unit: string, price: float, kind?: string}>  $lines  свободные строки
     */
    public function __invoke(Party $party, User $by, string $direction, ChargeKind $kind, CarbonInterface $dueAt, bool $vat, ?CarbonInterface $storageUntil = null, array $chargeIds = [], array $lines = [],
        ?Vehicle $vehicle = null, ?int $dealId = null, ?int $offerId = null, ?string $externalNo = null, ?string $notes = null): Invoice
    {
        Nav::forgetStaffCounts();
        $invoice = DB::transaction(function () use ($party, $by, $direction, $kind, $dueAt, $vat, $storageUntil, $chargeIds, $lines, $vehicle, $dealId, $offerId, $externalNo, $notes) {
            $year = (int) now()->format('Y');
            $invoice = Invoice::create([
                'direction' => $direction, 'year' => $direction === 'issued' ? $year : null, 'number' => $direction === 'issued' ? Numbering::next($year) : null,
                'external_no' => $externalNo, 'kind' => $kind, 'party_id' => $party->id, 'vehicle_id' => $vehicle?->id, 'deal_id' => $dealId, 'offer_id' => $offerId,
                'issued_at' => now()->toDateString(), 'due_at' => Carbon::instance($dueAt)->toDateString(), 'vat' => $vat, 'state' => InvoiceState::Issued, 'notes' => $notes, 'created_by' => $by->id,
            ]);
            $total = 0.0;
            if ($vehicle && $storageUntil) {
                $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                $until = Carbon::instance($storageUntil)->startOfDay();
                if ($vehicle->storage_billed_until && $until->lte($vehicle->storage_billed_until)) {
                    throw ValidationException::withMessages(['storage_until' => 'Хранение по '.$vehicle->storage_billed_until->format('d.m.Y').' уже выставлено']);
                }
                $segments = Accrual::storage($vehicle, $until);
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
            $invoice->update(['total' => round($total, 2)]);
            $vehicle?->log($direction === 'issued' ? EventType::Invoiced : EventType::Owed, $by, ['label' => $invoice->label(), 'amount' => $invoice->total, 'party' => $party->name]);
            if ($direction === 'issued') {
                $this->pdf->attach($invoice->fresh(['charges', 'party', 'vehicle.brand', 'vehicle.model']));
            }

            return $invoice;
        });
        InvoiceIssued::dispatch($invoice, $by);

        return $invoice;
    }
}
