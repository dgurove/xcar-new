<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Аннулировать счёт без оплат: начисления освобождаются, строки хранения гаснут и период снова не выставлен. */
final class VoidInvoice
{
    public function __invoke(Invoice $invoice, User $by, ?string $reason = null): Invoice
    {
        Nav::forgetStaffCounts();
        $invoice = DB::transaction(function () use ($invoice, $by, $reason) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->paid > 0) {
                throw ValidationException::withMessages(['invoice' => 'По счёту есть оплаты — сначала отмените их']);
            }
            $storage = $invoice->charges()->where('kind', ChargeKind::Storage)->orderBy('period_from')->get();
            Charge::where('invoice_id', $invoice->id)->where('kind', '!=', ChargeKind::Storage)->update(['invoice_id' => null]);
            foreach ($storage as $c) {
                $c->update(['voided_at' => now(), 'void_reason' => $reason]);
            }
            if ($storage->isNotEmpty() && $invoice->vehicle_id) {
                $vehicle = Vehicle::whereKey($invoice->vehicle_id)->lockForUpdate()->first();
                $first = $storage->first()->period_from;
                if ($vehicle && $vehicle->storage_billed_until && $vehicle->storage_billed_until->gte($first)) {
                    $vehicle->update(['storage_billed_until' => $first->copy()->subDay()->lt($vehicle->accepted_at?->startOfDay()) ? null : $first->copy()->subDay()]);
                }
            }
            $invoice->update(['state' => InvoiceState::Void, 'voided_at' => now(), 'void_reason' => $reason]);
            $invoice->vehicle?->log(EventType::InvoiceVoided, $by, ['label' => $invoice->label()]);

            return $invoice;
        });
        InvoiceVoided::dispatch($invoice, $by);

        return $invoice;
    }
}
