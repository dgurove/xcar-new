<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\DealContract;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\GarageSelling;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Продана. Менеджер жмёт «Продаю» из «В продаже» и пишет только цену — её не отклоняем, нам в Telegram:
 * назначить вознаграждение и выставить счёт. Сотрудник вносит итог с любого рабочего этапа — когда сделка со
 * страховой закрыта. Покупатель с гаражом (20.10.2026, владелец: «только отметка») — «Продана» без суммы: он
 * платит свою цену счётом, сколько выручил — не наше; счёт уже оплачен — расчёт закрыт сразу.
 */
final class MarkGarageSold
{
    public function __invoke(Car $car, array $data, User $by): Car
    {
        if ($car->isFrozen()) {
            throw ValidationException::withMessages(['sold_price' => 'По ТС выставлен счёт: сначала аннулируйте его']);
        }
        $staff = $by->isAdmin();
        if ($car->papersPending()) {
            throw ValidationException::withMessages(['sold_price' => 'Документы со страховой ещё не закрыты: продавать рано']);
        }
        if ($staff ? ! $car->state->isWorking() : ($car->state !== CarState::Selling || $car->manager_id !== $by->id)) {
            throw ValidationException::withMessages(['sold_price' => 'Продать можно ТС в продаже']);
        }

        $buyer = $car->forBuyer();
        $car->moveTo(CarState::Sold, [
            'sold_price' => $buyer ? null : $data['sold_price'],
            'sold_at' => $data['sold_at'] ?? now(),
            'buyer_name' => $staff ? ($data['buyer_name'] ?? null) : null,
            'buyer_phone' => $staff ? ($data['buyer_phone'] ?? null) : null,
        ], $by);
        // Совкомбанк (10.10.2026): цена продажи — цена сделки и договора; счёт ПРАЙМ и договор встанут, когда менеджер назовёт
        // покупателя, а мы — его вознаграждение (`SyncDealInvoices`).
        if ($car->settledByDeal()) {
            $deal = $car->deal;
            $deal->update(['amount' => (int) $data['sold_price']]);
            DealContract::for($deal)->update(['price' => DealContract::priceFor($deal->fresh())]);
            app(SyncDealInvoices::class)($deal->fresh(), $by);
        }
        if ($buyer && $car->buyerPaid()) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $by);
        }
        GarageChanged::dispatch($car);
        if (! $staff && ! $buyer) {
            NotifyOwner::dispatch(new GarageSelling($car->load(['offer', 'manager', 'costs'])));
        }

        return $car;
    }
}
