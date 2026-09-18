<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
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
 * Выставить счёт одному контрагенту: отрезки хранения становятся строками
 * (и двигают `storage_billed_until`), невыставленные начисления привязываются,
 * свободные строки добавляются; номер — сквозной в году; PDF — снимок сразу.
 * `owed` — наше обязательство (номера нет, есть чужой).
 */
final class IssueInvoice
{
    public function __construct(private InvoicePdf $pdf) {}

    /**
     * @param  list<array{from: CarbonInterface, to: CarbonInterface, days: int, rate: float}>  $storage  отрезки хранения
     * @param  list<int>  $chargeIds  невыставленные начисления
     * @param  list<array{title: string, qty: float, unit: string, price: float, kind?: string}>  $lines  свободные строки
     */
    public function __invoke(Party $party, User $by, string $direction, ChargeKind $kind, CarbonInterface $dueAt, bool $vat, array $storage = [], array $chargeIds = [], array $lines = [],
        ?Vehicle $vehicle = null, ?int $dealId = null, ?int $offerId = null, ?string $externalNo = null, ?string $notes = null): Invoice
    {
        Nav::forgetStaffCounts();
        $invoice = DB::transaction(function () use ($party, $by, $direction, $kind, $dueAt, $vat, $storage, $chargeIds, $lines, $vehicle, $dealId, $offerId, $externalNo, $notes) {
            $year = (int) now()->format('Y');
            $invoice = Invoice::create([
                'direction' => $direction, 'year' => $direction === 'issued' ? $year : null, 'number' => $direction === 'issued' ? Numbering::next($year) : null,
                'external_no' => $externalNo, 'kind' => $kind, 'party_id' => $party->id, 'vehicle_id' => $vehicle?->id, 'deal_id' => $dealId, 'offer_id' => $offerId,
                'issued_at' => now()->toDateString(), 'due_at' => Carbon::instance($dueAt)->toDateString(), 'vat' => $vat, 'state' => InvoiceState::Issued, 'notes' => $notes, 'created_by' => $by->id,
            ]);
            $total = 0.0;
            if ($vehicle && $storage) {
                $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                $until = $vehicle->storage_billed_until;
                foreach ($storage as $s) {
                    $from = Carbon::instance($s['from'])->startOfDay();
                    $to = Carbon::instance($s['to'])->startOfDay();
                    if ($until && $from->lte($until)) {
                        throw ValidationException::withMessages(['storage' => 'Хранение до '.$until->format('d.m.Y').' уже выставлено']);
                    }
                    $charge = Charge::create([
                        'party_id' => $party->id, 'vehicle_id' => $vehicle->id, 'invoice_id' => $invoice->id, 'kind' => ChargeKind::Storage,
                        'title' => 'Хранение '.$from->format('d.m.Y').' – '.$to->format('d.m.Y'), 'qty' => $s['days'], 'unit' => 'day', 'price' => $s['rate'],
                        'amount' => round($s['days'] * $s['rate'], 2), 'period_from' => $from, 'period_to' => $to, 'created_by' => $by->id,
                    ]);
                    $total += $charge->amount;
                    $until = $to->copy();
                }
                $vehicle->update(['storage_billed_until' => $until]);
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

            return $invoice;
        });
        if ($direction === 'issued') {
            $this->pdf->attach($invoice->fresh(['charges', 'party', 'vehicle.brand', 'vehicle.model']));
        }
        InvoiceIssued::dispatch($invoice, $by);

        return $invoice;
    }
}
