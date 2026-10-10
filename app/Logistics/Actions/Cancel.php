<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Garage\Actions\RemoveCost;
use App\Garage\Cost;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отмена перевозки — админу и логисту, пока машину не сдали: неоплаченный долг исполнителю и расход гаража уходят.
 * `system` — отменяет сама система (вывоз поручили менеджеру, `SyncPickupTransport`): роль того, кто нажал, не проверяется.
 */
final class Cancel
{
    public function __construct(private VoidInvoice $void, private RemoveCost $removeCost) {}

    public function __invoke(Transport $t, User $by, bool $system = false): Transport
    {
        if (! $system && ! $by->seesAllTransports()) {
            throw ValidationException::withMessages(['transport' => 'Отменяет логист']);
        }
        if (! $t->isOpen()) {
            throw ValidationException::withMessages(['transport' => 'Перевозка '.mb_strtolower($t->state->label())]);
        }
        DB::transaction(function () use ($t, $by) {
            if ($t->invoice && $t->invoice->state === InvoiceState::Issued && (float) $t->invoice->paid === 0.0) {
                ($this->void)($t->invoice, $by, 'Перевозка отменена');
            }
            // Расход гаража уходит вместе с перевозкой; по машине уже выставлен счёт — строка в нём остаётся, и связь с ней тоже.
            $cost = $t->garage_cost_id ? Cost::find($t->garage_cost_id) : null;
            if ($cost && ! $cost->car->isFrozen()) {
                ($this->removeCost)($cost, $by);
                $cost = null;
            }
            $t->update(['state' => TransportState::Cancelled, 'cancelled_at' => now(), 'garage_cost_id' => $cost?->id]);
        });
        TransportMoved::dispatch($t, $by);

        return $t;
    }
}
