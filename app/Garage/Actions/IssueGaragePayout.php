<?php

namespace App\Garage\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
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
 * Покупатель оплатил машину из гаража — менеджеру к выплате его расходы и вознаграждение (`owed`, «Вам к выплате»).
 * Выплачивать нечего — расчёт закрыт сразу. Живая выплата уже есть — вторую не заводим: оплаченная закрывает расчёт
 * (счёт покупателю перевыставили, а выплата прошла раньше), неоплаченная ждёт. Аннулировали выплату — сотрудник
 * заводит её заново кнопкой, при желании с другим вознаграждением.
 */
final class IssueGaragePayout
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(Car $car, User $by, ?int $commission = null): ?Invoice
    {
        $car->loadMissing(['offer', 'manager', 'costs', 'invoice', 'payoutInvoice']);
        if ($car->invoice_to !== 'buyer' || $car->invoice?->state !== InvoiceState::Paid || $car->state !== CarState::Sold) {
            throw ValidationException::withMessages(['car' => 'Выплата — после оплаты покупателем']);
        }
        $live = $car->payoutInvoice?->state === InvoiceState::Void ? null : $car->payoutInvoice;
        if ($live) {
            if ($live->state === InvoiceState::Paid) {
                $car->moveTo(CarState::Settled, ['settled_at' => now()], $by);
                GarageChanged::dispatch($car);
            }

            return $live;
        }
        if ($commission !== null) {
            $car->update(['commission' => $commission]);
        }
        $payout = Settlement::of($car->refresh()->load('costs'))['payout'];
        if (! $car->manager || $payout < 0.005) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $by);
            GarageChanged::dispatch($car);

            return null;
        }

        $invoice = DB::transaction(function () use ($car, $payout, $by) {
            $invoice = ($this->issue)(Party::forUser($car->manager), $by, 'owed', ChargeKind::AgentFee, WorkDays::add(now(), 5),
                lines: [['title' => 'Расходы и вознаграждение по '.$car->offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => $payout, 'kind' => ChargeKind::AgentFee->value]],
                offerId: $car->offer_id);
            $car->update(['payout_invoice_id' => $invoice->id]);
            $car->log($by, ['do' => 'payout', 'amount' => $payout]);

            return $invoice;
        });
        GarageChanged::dispatch($car);
        $car->manager->notify(MoneyNotice::garageInvoice($invoice->fresh(), $car));

        return $invoice;
    }
}
