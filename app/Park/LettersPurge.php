<?php

namespace App\Park;

use App\Billing\Charge;
use App\Billing\Invoice;
use App\Billing\InvoiceState;

/**
 * Когда у выданной ТС можно стереть файлы из писем и заморозить письма (`PurgeLetters`). 06.11.2026, владелец: стирали сразу
 * при выдаче — и акты приёма и выдачи пропали, когда по ним сверяли даты для счёта и акта за месяц. Правило: «чистить только
 * после того, как месяц, в котором выдана ТС, полностью закрыт и оплачен» — месяц выдачи кончился, хранение выставлено по
 * день выдачи и все счета с её начислениями оплачены. У вендора без счетов — только конец месяца выдачи.
 */
final class LettersPurge
{
    public static function due(Vehicle $vehicle): bool
    {
        if ($vehicle->state !== VehicleState::Released || ! $vehicle->released_at || $vehicle->released_at->gte(now()->startOfMonth())) {
            return false;
        }
        if (! ($vehicle->vendor?->kind?->billable() ?? false)) {
            return true;
        }
        if (! $vehicle->storage_billed_until || $vehicle->storage_billed_until->lt($vehicle->released_at->copy()->startOfDay())) {
            return false;
        }
        $invoices = Charge::where('vehicle_id', $vehicle->id)->whereNull('voided_at')->whereNotNull('invoice_id')->distinct()->pluck('invoice_id');

        return $invoices->isNotEmpty()
            && ! Invoice::whereIn('id', $invoices)->whereNotIn('state', [InvoiceState::Paid, InvoiceState::Void])->exists();
    }
}
