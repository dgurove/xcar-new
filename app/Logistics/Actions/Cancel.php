<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Garage\Actions\RemoveCost;
use App\Garage\Cost;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отмена вывоза — админу, пока машину не привезли, с причиной: неоплаченный долг логисту и расход гаража уходят. Свою
 * заявку от своего заказчика отменяет сам логист (18.10.2026).
 * `system` — отменяет сама система (продажа ушла в архив, предложение удалили): роль не проверяется.
 */
final class Cancel
{
    public function __construct(private VoidInvoice $void, private RemoveCost $removeCost, private SetCarPlace $place) {}

    public function __invoke(Transport $t, ?User $by, ?string $reason = null, bool $system = false, bool $batch = false): Transport
    {
        if (! $system && ! (($by?->leadsLogistics() && ! $t->isOrder()) || ($t->isOrder() && $by && $t->hasLogist($by)))) {
            throw ValidationException::withMessages(['transport' => 'Отменяют админ и модераторы']);
        }
        if (! $t->isOpen()) {
            throw ValidationException::withMessages(['transport' => 'Вывоз '.mb_strtolower($t->state->label())]);
        }
        DB::transaction(function () use ($t, $by, $reason) {
            if ($t->invoice && $t->invoice->state === InvoiceState::Issued && (float) $t->invoice->paid === 0.0) {
                ($this->void)($t->invoice, $by, 'Вывоз отменён');
            }
            // Расход гаража уходит вместе с задачей; по машине уже выставлен счёт — строка в нём остаётся, и связь с ней тоже.
            $cost = $t->garage_cost_id ? Cost::find($t->garage_cost_id) : null;
            if ($cost && ! $cost->car->isFrozen()) {
                ($this->removeCost)($cost, $by);
                $cost = null;
            }
            $t->update(['state' => TransportState::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => trim((string) $reason) ?: null, 'garage_cost_id' => $cost?->id]);
            // Машину уже везли — где она теперь, не знаем: у владельца, пока вывоз не заведут снова.
            if (($offer = $t->saleOffer()) && $offer->car_place === CarPlace::Moving) {
                ($this->place)($offer, CarPlace::Owner, $by);
            }
        });
        TransportMoved::dispatch($t, $by, 'cancelled', $batch);

        return $t;
    }
}
