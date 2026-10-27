<?php

namespace App\Workflow\Presets;

/**
 * Выкуп у лизинга (Каркаде): мы выкупаем машины оптом, менеджер платит нам по счёту ПРАЙМ (`DealFormat::Wholesale`).
 * Машина стоит у поставщика в его городе. У Каркаде каждый раз свой лот (20, 30, 100 машин, владелец 09.10.2026): приняли
 * подтверждения — сделки ждут заявки («Собираем заявку поставщику»); когда решит админ, все они уходят поставщику одним
 * письмом со списком (`SupplierRequest`, «Заявка поставщику» в CRM) и ждут ответа несколько дней («Заявка у
 * поставщика», без срока); ответ — «Ответ по списку». Согласие менеджера не спрашиваем — цену он уже подтвердил, после
 * «да» поставщика сразу счёт. Дальше оплата, мы платим поставщику, менеджер забирает машину у поставщика. Полная оплата
 * счёта двигает этап сама (AdvanceOnPayment).
 */
final class Stock extends Route
{
    public function blocks(): array
    {
        $sale = $this->saleBlocks();

        return self::withGarageBlocks([
            'sale' => $sale['sale'],
            // Два блока, а не два этапа одного: «идёт N дн» менеджер видит с входа в блок — ответ ждём с отправки заявки.
            'request' => ['name' => 'Собираем заявку поставщику', 'text' => 'Отправим поставщику заявку вместе с другими автомобилями'],
            'agreement' => ['name' => 'Заявка у поставщика', 'text' => 'Заявка у поставщика, ответ обычно через несколько дней. Счёт придёт, когда поставщик подтвердит'],
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
        // Принятое ждёт заявки: письмо поставщику — одно на всю пачку (`SupplierRequest`), не письмо этапа.
        foreach (['bidding', 'choosing'] as $key) {
            $head[$key]['exits'] = array_map(fn (array $e) => $e[0] === 'Подтверждение принято' ? [$e[0], $e[1], 'request', ...array_slice($e, 3)] : $e, $head[$key]['exits']);
        }
        $claimed = ['name' => 'Заявка у поставщика', 'limit_minutes' => null] + $head['claimed'];
        unset($claimed['letter'], $head['claimed']);

        return [
            ...$head,
            'request' => [
                // Ход наш — без срока заявка не уходила по 9 дней (18.10.2026): двое суток, потом красным.
                'name' => 'Собираем заявку поставщику', 'block' => 'request', 'waits_for' => 'us', 'offer_state' => 'sold', 'limit_minutes' => 2 * self::DAY,
                'exits' => [['Заявка отправлена', 'staff', 'claimed'], ['Поставщик отказал', 'staff', 'supplier_declined']],
            ],
            'claimed' => $claimed,
            'invoice' => [
                'name' => 'Счёт выставлен менеджеру', 'block' => 'payment', 'waits_for' => 'manager', 'limit_minutes' => 14 * self::DAY, 'asks' => 'document',
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
