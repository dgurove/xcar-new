<?php

namespace App\Workflow\Presets;

/**
 * Выкуп у лизинга (Каркаде): мы выкупаем машину оптом, менеджер платит нам по счёту ПРАЙМ (`DealFormat::Wholesale`).
 * Машина стоит у поставщика в его городе. Выбрали подтверждение — ждём ответа поставщика, что отдаёт (09.10.2026,
 * владелец: отвечает одним ответом на список, «Ответ по списку» в CRM); согласие менеджера не спрашиваем — цену он уже
 * подтвердил, после «да» поставщика сразу счёт. Дальше оплата, мы платим поставщику, менеджер забирает машину у
 * поставщика. Писем поставщику маршрут не пишет. Полная оплата счёта двигает этап сама (AdvanceOnPayment).
 */
final class Stock extends Route
{
    public function blocks(): array
    {
        $sale = $this->saleBlocks();

        return self::withGarageBlocks([
            'sale' => $sale['sale'],
            'agreement' => ['name' => 'Согласование с поставщиком', 'text' => 'Согласовываем покупку с поставщиком. Счёт придёт, когда поставщик подтвердит'],
            'payment' => ['name' => 'Оплата', 'text' => 'Счёт выставлен. После оплаты передадим автомобиль'],
            'handover' => ['name' => 'Получение автомобиля', 'text' => 'Автомобиль оплачен, его можно забирать у поставщика'],
            'won' => $sale['won'],
            'declined' => $sale['declined'],
            'nobody' => $sale['nobody'],
        ]);
    }

    public function stages(): array
    {
        $head = $this->head(agreed: [['Поставщик согласовал', 'staff', 'invoice', 'buyer'], ['Поставщик согласовал', 'staff', 'garage_payment', 'garage']]);
        // Ответ поставщика ждём без письма: список ему уходит одним письмом мимо маршрута.
        $head['claimed']['name'] = 'Согласование с поставщиком';
        unset($head['claimed']['letter']);

        return [
            ...$head,
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
                // Гаражную сделку «платит менеджер» с площадки не выдаём: бумаги готовы — её везёт вывоз (06.10.2026).
                'exits' => [['Поставщику оплачено', 'staff', 'pickup', 'deal'], ['Поставщику оплачено', 'staff', 'closed_won', 'keeps']],
            ],
            'pickup' => [
                'name' => 'Выдача у поставщика', 'block' => 'handover', 'waits_for' => 'manager', 'limit_minutes' => 5 * self::DAY,
                'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Автомобиль оплачен поставщику. Заберите его у поставщика и отметьте, что забрали',
                'staff_fields' => [['label' => 'Адрес выдачи', 'type' => 'textarea'], ['label' => 'Контакт на выдаче'], ['label' => 'Дата выдачи']],
                'exits' => [['Автомобиль забрал', 'manager', 'closed_won'], ['Автомобиль передан', 'staff', 'closed_won']],
            ],
            ...self::garageSegment(confirm: false),
            ...$this->tail(),
        ];
    }
}
