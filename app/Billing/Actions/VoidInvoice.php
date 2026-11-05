<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Аннулировать счёт без оплат: начисления, сделанные до счёта, освобождаются; строки хранения и строки,
 * добавленные в самом счёте, гаснут вместе с ним (их никто не начислял отдельно); период снова не выставлен.
 */
final class VoidInvoice
{
    /**
     * @param  bool  $revising  исправление (`FixClosingDocument`): тот же период сразу выставляется заново, а «выставлено по»
     *                          у ТС с более поздним счётом оно вернёт само — счёт позже этому не мешает
     */
    public function __invoke(Invoice $invoice, ?User $by, ?string $reason = null, bool $revising = false): Invoice
    {
        Nav::forgetStaffCounts();
        $invoice = DB::transaction(function () use ($invoice, $by, $reason, $revising) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            // Зачёт удержанного вознаграждения — не деньги: гаснет вместе со счётом. Живые оплаты — отменить сначала.
            if ($invoice->payments()->whereNotIn('source', PaymentSource::soft())->exists()) {
                throw ValidationException::withMessages(['invoice' => 'По счёту есть оплаты, сначала отмените их']);
            }
            Payment::where('invoice_id', $invoice->id)->whereNull('voided_at')->update(['voided_at' => now()]);
            $storage = $invoice->charges()->where('kind', ChargeKind::Storage)->orderBy('period_from')->get();
            // Хранение ТС выставлено и позже (сентябрь, когда октябрь уже счётом): откат «выставлено по» открыл бы и
            // октябрь, его бы выставили второй раз (05.11.2026). Сначала — более поздний счёт.
            if (! $revising && ($later = self::later($invoice, $storage))) {
                throw ValidationException::withMessages(['invoice' => 'Хранение этих ТС выставлено и позже — сначала аннулируйте счёт '.$later->label()]);
            }
            Charge::where('invoice_id', $invoice->id)->where('kind', '!=', ChargeKind::Storage)->where('created_at', '<', $invoice->created_at)->update(['invoice_id' => null]);
            Charge::where('invoice_id', $invoice->id)->whereNull('voided_at')->update(['voided_at' => now(), 'void_reason' => $reason]);
            // Период снова не выставлен — у каждой ТС счёта (у реестра их много): с первого дня её строк.
            foreach ($storage->whereNotNull('vehicle_id')->groupBy('vehicle_id') as $vehicleId => $rows) {
                $vehicle = Vehicle::whereKey($vehicleId)->lockForUpdate()->first();
                $first = $rows->first()->period_from;
                if ($vehicle && $vehicle->storage_billed_until && $vehicle->storage_billed_until->gte($first)) {
                    $vehicle->update(['storage_billed_until' => Closing::billedBefore($rows, $vehicle)]);
                }
                if ($invoice->isRegistry()) {
                    $vehicle?->log(EventType::InvoiceVoided, $by, ['label' => 'реестр '.$invoice->label()]);
                }
            }
            $invoice->update(['state' => InvoiceState::Void, 'voided_at' => now(), 'void_reason' => $reason]);
            $invoice->vehicle?->log(EventType::InvoiceVoided, $by, ['label' => $invoice->label()]);

            return $invoice;
        });
        if ($invoice->park) {
            Closing::forgetPending();
        }
        InvoiceVoided::dispatch($invoice, $by);

        return $invoice;
    }

    /** Живой счёт с хранением тех же ТС после строк этого — его аннулируют первым. */
    private static function later(Invoice $invoice, $storage): ?Invoice
    {
        $last = $storage->whereNotNull('vehicle_id')->groupBy('vehicle_id')->map(fn ($rows) => $rows->max('period_to'));
        foreach ($last as $vehicleId => $to) {
            $charge = Charge::where('vehicle_id', $vehicleId)->where('kind', ChargeKind::Storage)->whereNull('voided_at')->where('invoice_id', '!=', $invoice->id)
                ->where('period_from', '>', $to)->whereHas('invoice', fn ($q) => $q->where('state', '!=', InvoiceState::Void->value))->with('invoice')->first();
            if ($charge) {
                return $charge->invoice;
            }
        }

        return null;
    }
}
