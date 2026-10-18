<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\WorkDays;
use App\Garage\Actions\RemoveCost;
use App\Garage\Actions\UpdateCost;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\Payer;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Users\User;

/**
 * Стоимость вывоза — расход по машине: долг логисту «мы должны» (`owed`, `ChargeKind::Tow`, «Оплаты → Мы должны»;
 * 17.10.2026, владелец: «платим только Татьяне», со своими исполнителями она рассчитывается сама); у гаражной машины ещё
 * строка расхода «Вывоз», платили мы. Менеджер и наши люди везут без суммы. Поменяли сумму или логиста — прежний
 * неоплаченный счёт аннулируется и выставляется новый; оплаченный не трогаем.
 */
final class SettleCost
{
    public const DAYS = 5;

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private UpdateCost $updateCost, private RemoveCost $removeCost) {}

    public function __invoke(Transport $t, User $by): void
    {
        // Заявка логиста от его заказчика: XCar за неё не платит, деньги — её (`client_price`, `executor_cost`).
        if ($t->isOrder()) {
            return;
        }
        // Исполнителя и счёт — заново: до назначения связь могла загрузиться пустой.
        $t->load(['invoice', 'logist'])->loadMissing(['offer.garageCar', 'vehicle']);
        $cost = (float) $t->cost;
        $party = $cost > 0 && $t->channel === Channel::Logist && $t->logist ? Party::forUser($t->logist) : null;

        // Аннулированный счёт (руками в «Оплатах» или прошлой правкой) — всё равно что его нет: долг выставится заново.
        $old = $t->invoice?->state === InvoiceState::Void ? null : $t->invoice;
        $same = $old && $party && $old->party_id === $party->id && (float) $old->total === $cost;
        if ($old && ! $same && (float) $old->paid === 0.0) {
            ($this->void)($old, $by, 'Вывоз: логист или стоимость изменились');
            $old = null;
        }
        if (! $old) {
            $t->update(['invoice_id' => null]);
        }
        // Оплаченный, хоть частично, счёт не трогаем: перевыставлять поверх уже переведённых денег нельзя.
        if ($party && ! $same && ! $old) {
            $invoice = ($this->issue)($party, $by, 'owed', ChargeKind::Tow, WorkDays::add(now(), self::DAYS),
                lines: [['title' => 'Вывоз, '.$t->title().($t->city() ? ', '.$t->city() : '').($t->distance_km ? ', '.$t->distance_km.' км' : ''), 'qty' => 1, 'unit' => 'pc', 'price' => $cost, 'kind' => ChargeKind::Tow->value]],
                vehicle: $t->parkVehicle(), offerId: $t->saleOffer()?->id);
            $t->update(['invoice_id' => $invoice->id]);
        }

        $this->garageCost($t, $by, $cost);
    }

    /**
     * Гаражная машина: строка расхода «Вывоз» — одна на задачу, правится вместе со стоимостью (`UpdateCost`,
     * `RemoveCost`; новая — как «Оплата поставщику» у `CloseGarageDeal`: строка, запись в историю машины, событие).
     */
    private function garageCost(Transport $t, User $by, float $cost): void
    {
        $car = $t->saleOffer()?->garageCar;
        $row = $t->garage_cost_id ? Cost::find($t->garage_cost_id) : null;
        if (! $car || $car->isFrozen()) {
            return;
        }
        if ($cost <= 0 || $t->channel !== Channel::Logist) {
            if ($row) {
                ($this->removeCost)($row, $by);
            }
            $t->update(['garage_cost_id' => null]);

            return;
        }
        $data = ['title' => 'Вывоз'.($t->logist ? ', '.$t->logist->shortName() : ''), 'amount' => $cost];
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
