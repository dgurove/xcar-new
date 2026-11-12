<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Actions\UpdateDealMoney;
use App\Offers\CommissionMode;
use App\Offers\DealContract;
use App\Offers\Margin;
use App\Support\Money;
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
        // Лишний ноль и продажа в минус — переспрос (12.11.2026: Haval 10 000 000 при закупочной 476 190), как цена в CRM
        // (`Margin::check`, согласие `price_ok`, форма ставит его сама после ошибки `price_check`).
        $check = $buyer ? null : Margin::check((int) $data['sold_price'], $car->deal?->cost);
        if ($check && in_array($check['tone'], ['typo', 'loss'], true) && empty($data['price_ok'])) {
            throw ValidationException::withMessages(['sold_price' => $check['title'], 'price_check' => $check['tone']]);
        }
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
            // Продали за другую цену (после «Не продана») — прежнее вознаграждение считали от прежней, ставим заново.
            $repriced = $deal->amount !== null && $deal->amount !== (int) $data['sold_price'];
            $deal->update(['amount' => (int) $data['sold_price']]);
            DealContract::for($deal)->update(['price' => DealContract::priceFor($deal->fresh())]);
            // Вознаграждение — сразу по доле менеджера (12.11.2026, `Deal::suggestedCommission`): «Назначить вознаграждение»
            // висело у четырёх продаж; поправить — прежней шторкой «Изменить» в CRM. Счета пересобирает `UpdateDealMoney`.
            $deal = $deal->fresh();
            if (($deal->commission === null || $repriced) && $deal->moneyEditable() && ($fee = $deal->suggestedCommission()) !== null) {
                app(UpdateDealMoney::class)($deal, $by, $fee, CommissionMode::Withheld, note: 'Вознаграждение '.Money::rub($fee).': наша доля навара '.$deal->ourGarageShare().' %');
            } else {
                app(SyncDealInvoices::class)($deal, $by);
            }
        }
        if ($buyer && $car->buyerPaid()) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $by);
        }
        GarageChanged::dispatch($car);

        return $car;
    }
}
