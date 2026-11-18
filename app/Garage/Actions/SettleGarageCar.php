<?php

namespace App\Garage\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\WorkDays;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Garage\Settlement;
use App\Notifications\MoneyNotice;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Расчёт по машине: счёт одной строкой «Транспортное средство» на цену продажи минус расходы менеджера минус его
 * вознаграждение (`Settlement::due`) — менеджеру или его покупателю (плательщика выбирают при счёте). Вознаграждение и свои
 * расходы менеджер получает не от нас (18.11.2026, начальник: «вознаграждения менеджерам мы не выплачиваем никогда»;
 * владелец: расходы «просто вычитаем из суммы за услуги подбора»). Ноль, в минус или машина, взятая под себя, — расчёт
 * закрывается без документа. Поставщику платил сам менеджер — документы на нём: счёт только менеджеру.
 */
final class SettleGarageCar
{
    public function __construct(private IssueInvoice $issue) {}

    /** @param  Party|array|null  $buyer  кто платит вместо менеджера: контрагент или поля нового (kind, name, inn, phone) */
    public function __invoke(Car $car, User $by, ?int $commission = null, Party|array|null $buyer = null): ?Invoice
    {
        if ($car->forBuyer()) {
            throw ValidationException::withMessages(['car' => 'У покупателя расчёта нет: он платит свою цену счётом']);
        }
        if ($car->sold_price === null) {
            throw ValidationException::withMessages(['car' => 'Сначала внесите итог продажи']);
        }
        if ($car->invoice_id) {
            throw ValidationException::withMessages(['car' => 'Счёт уже выставлен']);
        }
        if ($buyer && (! $car->manager || $car->managerPaidSupplier())) {
            throw ValidationException::withMessages(['party_id' => 'Поставщику платил менеджер, счёт только ему']);
        }

        if ($car->manager) {
            $car->update(['commission' => $commission]);
        }
        $due = Settlement::of($car)['due'];
        if (! $car->manager || $due === null || $due < 0.005) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $by);
            GarageChanged::dispatch($car);

            return null;
        }

        $invoice = DB::transaction(function () use ($car, $by, $due, $buyer) {
            $offer = $car->offer;
            // Новый плательщик заводится только здесь, после проверок: отказ не оставляет пустых контрагентов.
            $party = $buyer ? (is_array($buyer) ? Party::create($buyer) : $buyer) : Party::forUser($car->manager);
            $invoice = ($this->issue)($party, $by, 'issued', ChargeKind::Sale, WorkDays::add(now(), 5),
                lines: [['title' => $offer->invoiceTitle(), 'qty' => 1, 'unit' => 'pc', 'price' => round($due, 2), 'kind' => ChargeKind::Sale->value]], offerId: $offer->id);
            $car->update(['invoice_id' => $invoice->id, 'invoice_to' => $buyer ? 'buyer' : 'manager']);
            $car->log($by, ['do' => 'invoice', 'to' => $buyer ? 'buyer' : 'manager', 'amount' => round($due, 2)]);

            return $invoice;
        });

        GarageChanged::dispatch($car);
        $buyer || $car->manager->notify(MoneyNotice::garageInvoice($invoice->fresh(), $car));

        return $invoice;
    }
}
