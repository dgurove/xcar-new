<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\WorkDays;
use App\Garage\Actions\RemoveCost;
use App\Garage\Actions\UpdateCost;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
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

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private UpdateCost $updateCost, private RemoveCost $removeCost) {}

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

        // Аннулированный счёт (руками в «Оплатах» или прошлой правкой) — всё равно что его нет: долг выставится заново.
        $old = $t->invoice?->state === InvoiceState::Void ? null : $t->invoice;
        $same = $old && $party && $old->party_id === $party->id && (float) $old->total === $cost;
        if ($old && ! $same && (float) $old->paid === 0.0) {
            ($this->void)($old, $by, 'Перевозка: исполнитель или стоимость изменились');
            $old = null;
        }
        if (! $old) {
            $t->update(['invoice_id' => null]);
        }
        // Оплаченный, хоть частично, счёт не трогаем: перевыставлять поверх уже переведённых денег нельзя.
        if ($party && ! $same && ! $old) {
            $invoice = ($this->issue)($party, $by, 'owed', ChargeKind::Tow, WorkDays::add(now(), self::DAYS),
                lines: [['title' => 'Перевозка, '.$t->title().($t->regions() ? ', '.$t->regions() : ''), 'qty' => 1, 'unit' => 'pc', 'price' => $cost, 'kind' => ChargeKind::Tow->value]],
                vehicle: $t->vehicle, offerId: $t->offer_id);
            $t->update(['invoice_id' => $invoice->id]);
        }

        $this->garageCost($t, $by, $cost);
    }

    /**
     * Внешний перевозчик — контрагент по названию среди прежних перевозчиков (долги `Tow`): тот же, что в прошлый раз.
     * Вендор или менеджер с таким же именем — не он: долг ушёл бы чужому контрагенту.
     */
    private function carrier(string $name): Party
    {
        return Party::where('name', $name)->whereIn('id', Invoice::where('direction', 'owed')->where('kind', ChargeKind::Tow)->select('party_id'))->first()
            ?? Party::create(['kind' => PartyKind::Company, 'name' => $name]);
    }

    /**
     * Гаражная машина: строка расхода «Перевозка» — одна на перевозку, правится вместе со стоимостью (`UpdateCost`,
     * `RemoveCost`; новая — как «Оплата поставщику» у `CloseGarageDeal`: строка, запись в историю машины, событие).
     */
    private function garageCost(Transport $t, User $by, float $cost): void
    {
        $car = $t->offer?->garageCar;
        $row = $t->garage_cost_id ? Cost::find($t->garage_cost_id) : null;
        if (! $car || $car->isFrozen()) {
            return;
        }
        if ($cost <= 0 || ! in_array($t->executor, [Executor::Partner, Executor::Carrier], true)) {
            if ($row) {
                ($this->removeCost)($row, $by);
            }
            $t->update(['garage_cost_id' => null]);

            return;
        }
        $data = ['title' => 'Перевозка'.($t->executorName() ? ', '.$t->executorName() : ''), 'amount' => $cost];
        if ($row) {
            if ($row->title !== $data['title'] || (float) $row->amount !== $cost) {
                ($this->updateCost)($row, $data, $by);
            }

            return;
        }
        $row = $car->costs()->create($data + ['spent_at' => now()->toDateString(), 'payer' => Payer::Xcar, 'kind' => Cost::TRANSPORT, 'created_by' => $by->id]);
        $car->log($by, ['do' => 'cost', 'title' => $row->title, 'amount' => $row->amount, 'payer' => Payer::Xcar->value]);
        GarageChanged::dispatch($car);
        $t->update(['garage_cost_id' => $row->id]);
    }
}
