<?php

namespace App\Workflow\Presets;

/**
 * Выкуп у лизинга (Каркаде): мы выкупаем машину, менеджер платит нам. Машина стоит на площадке поставщика в его
 * городе. Выбрали подтверждение — менеджер соглашается, платит по нашему счёту, мы платим поставщику, менеджер
 * забирает машину с площадки поставщика. Писем поставщику маршрут не пишет. Полная оплата счёта двигает этап
 * сама (AdvanceOnPayment).
 */
final class Stock extends Route
{
    public function blocks(): array
    {
        $sale = $this->saleBlocks();

        return self::withGarageBlocks([
            'sale' => $sale['sale'],
            'confirm' => ['name' => 'Согласие на покупку', 'text' => 'Автомобиль Ваш по Вашей цене. Подтвердите покупку'],
            'payment' => ['name' => 'Оплата', 'text' => 'Счёт выставлен. После оплаты передадим автомобиль'],
            'handover' => ['name' => 'Получение автомобиля', 'text' => 'Автомобиль оплачен, его можно забирать с площадки поставщика'],
            'won' => $sale['won'],
            'nobody' => $sale['nobody'],
        ]);
    }

    public function stages(): array
    {
        $head = $this->head();

        return [
            'draft' => $head['draft'],
            'bidding' => ['exits' => [['Подтверждение принято', 'staff', 'manager_confirm'], ['Срок приёма истёк', 'timer', 'choosing']]] + $head['bidding'],
            'choosing' => ['exits' => [['Подтверждение принято', 'staff', 'manager_confirm'], ['Подтверждений нет', 'staff', 'no_bids'], ['Возобновить приём', 'staff', 'bidding']]] + $head['choosing'],
            'manager_confirm' => [
                'name' => 'Согласие менеджера', 'block' => 'confirm', 'waits_for' => 'manager', 'limit_minutes' => 240, 'offer_state' => 'sold',
                'ask_title' => 'Подтвердите покупку', 'ask_text' => 'Автомобиль Ваш по Вашей цене. Подтвердите покупку или откажитесь от неё',
                // Отказ — обычный исход в приём: вход туда отменяет сделку, остальные подтверждения ждут в резерве.
                // «Забираю в гараж» — гаражной сделке «платим мы»: без счёта менеджеру, сразу оплата поставщику.
                'exits' => [['Покупаю', 'manager', 'invoice', 'buyer'], ['Забираю в гараж', 'manager', 'garage_payment', 'garage'], ['Отказываюсь', 'manager', 'bidding']],
            ],
            'invoice' => [
                'name' => 'Счёт выставлен менеджеру', 'block' => 'payment', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Оплатите счёт', 'ask_text' => 'Оплатите счёт и приложите платёжное поручение',
                'exits' => [['Платёжное поручение приложено', 'manager', 'payment_check']],
            ],
            'payment_check' => [
                'name' => 'Проверка оплаты', 'block' => 'payment', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Оплата получена', 'staff', 'supplier_payment'], ['Оплата не поступила', 'staff', 'invoice']],
            ],
            'supplier_payment' => [
                'name' => 'Оплата поставщику', 'block' => 'payment', 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'staff_fields' => [['label' => 'Дата оплаты'], ['label' => 'Номер платёжки']],
                'exits' => [['Поставщику оплачено', 'staff', 'pickup']],
            ],
            'pickup' => [
                'name' => 'Выдача на площадке поставщика', 'block' => 'handover', 'waits_for' => 'manager', 'limit_minutes' => 5 * self::DAY,
                'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Автомобиль оплачен поставщику. Заберите его с площадки и отметьте, что забрали',
                'staff_fields' => [['label' => 'Адрес площадки', 'type' => 'textarea'], ['label' => 'Контакт на площадке'], ['label' => 'Дата выдачи']],
                'exits' => [['Автомобиль забрал', 'manager', 'closed_won'], ['Автомобиль передан', 'staff', 'closed_won']],
            ],
            ...self::garageSegment(confirm: false),
            'closed_won' => $this->tail()['closed_won'],
            'no_bids' => $this->tail()['no_bids'],
        ];
    }
}
