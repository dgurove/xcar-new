<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\WorkDays;
use App\Garage\Cost;
use App\Garage\Payer;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Users\User;

/**
 * Стоимость перевозки — расход по машине (владелец 10.10.2026): долг исполнителю «мы должны» (`owed`, `ChargeKind::Tow`)
 * — партнёру как менеджеру (его «должны ему»), внешнему перевозчику — контрагентом по названию; у гаражной машины ещё
 * строка расхода «Перевозка», платили мы. Наш водитель — без суммы. Поменяли сумму или исполнителя — прежний
 * неоплаченный счёт аннулируется и выставляется новый; оплаченный не трогаем.
 */
final class SettleCost
{
    public const DAYS = 5;

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void) {}

    public function __invoke(Transport $t, User $by): void
    {
        // Исполнителя и счёт — заново: до назначения связь могла загрузиться пустой.
        $t->load(['invoice', 'executorUser'])->loadMissing(['offer.garageCar', 'vehicle']);
        $cost = (float) $t->cost;
        $party = match ($t->executor) {
            Executor::Partner => $t->executorUser ? Party::forUser($t->executorUser) : null,
            Executor::Carrier => $t->carrier ? $this->carrier($t->carrier) : null,
            default => null,
        };
        $party = $cost > 0 ? $party : null;

        $old = $t->invoice;
        $same = $old && $party && $old->party_id === $party->id && (float) $old->total === $cost;
        if ($old && ! $same && $old->state !== InvoiceState::Void && (float) $old->paid === 0.0) {
            ($this->void)($old, $by, 'Перевозка: исполнитель или стоимость изменились');
            $t->update(['invoice_id' => null]);
        }
        if ($party && ! $same && (! $old || (float) $old->paid === 0.0)) {
            $invoice = ($this->issue)($party, $by, 'owed', ChargeKind::Tow, WorkDays::add(now(), self::DAYS),
                lines: [['title' => 'Перевозка, '.$t->title().($t->regions() ? ', '.$t->regions() : ''), 'qty' => 1, 'unit' => 'pc', 'price' => $cost, 'kind' => ChargeKind::Tow->value]],
                vehicle: $t->vehicle, offerId: $t->offer_id);
            $t->update(['invoice_id' => $invoice->id]);
        }

        $this->garageCost($t, $by, $cost);
    }

    /** Внешний перевозчик — контрагент-компания по названию: тот же, что в прошлый раз. */
    private function carrier(string $name): Party
    {
        return Party::where('name', $name)->first() ?? Party::create(['kind' => PartyKind::Company, 'name' => $name]);
    }

    /** Гаражная машина: строка расхода «Перевозка» — одна на перевозку, правится вместе со стоимостью. */
    private function garageCost(Transport $t, User $by, float $cost): void
    {
        $car = $t->offer?->garageCar;
        $row = $t->garage_cost_id ? Cost::find($t->garage_cost_id) : null;
        if (! $car || $car->isFrozen()) {
            return;
        }
        if ($cost <= 0 || ! in_array($t->executor, [Executor::Partner, Executor::Carrier], true)) {
            $row?->delete();
            $t->update(['garage_cost_id' => null]);

            return;
        }
        $data = ['title' => 'Перевозка'.($t->executorName() ? ', '.$t->executorName() : ''), 'amount' => $cost];
        if ($row) {
            $row->update($data);

            return;
        }
        $row = $car->costs()->create($data + ['spent_at' => now()->toDateString(), 'payer' => Payer::Xcar, 'kind' => Cost::TRANSPORT, 'created_by' => $by->id]);
        $t->update(['garage_cost_id' => $row->id]);
    }
}
