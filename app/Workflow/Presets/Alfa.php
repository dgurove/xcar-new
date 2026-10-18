<?php

namespace App\Workflow\Presets;

/**
 * Поставщик выставляет счёт, после оплаты — передача договора (как именно — в чате). «Забираю в гараж» — гаражная ветка.
 *
 * Забирать — только после оплаты (10.10.2026, владелец: «альфастрахование — только с предварительной оплатой»,
 * `vendors.pay_before_pickup`, `Deal::mayPickUp`). С НДС покупатель платит ООО «Р.О.С.» (`DealScheme::Ros`), бывает и
 * ПРАЙМ: деньги пришли нам — сначала переводим их Альфе (наш шаг, ветка `ros` — «за ТС платят нам»), и только потом Альфа
 * подтверждает оплату.
 */
final class Alfa extends Route
{
    public function blocks(): array
    {
        $blocks = $this->saleBlocks();
        $blocks['signing']['text'] = 'Договор передаст поставщик или XCar, детали в чате';

        return self::withGarageBlocks($blocks);
    }

    public function stages(): array
    {
        $payment = $this->invoiceSegment('contract');
        $payment['invoice']['exits'] = [
            ['Платёжное поручение приложено', 'manager', 'ros_transfer', 'ros'],
            ['Платёжное поручение приложено', 'manager', 'payment_check', 'not_ros'],
        ];
        // Перевод с Р.О.С. — между оплатой и проверкой Альфой: порядок в блоке — порядок пути.
        $payment = ['invoice' => $payment['invoice'], 'ros_transfer' => null, 'payment_check' => $payment['payment_check']];
        $payment['ros_transfer'] = [
            'name' => 'Перевод поставщику', 'block' => 'payment', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
            'staff_fields' => [['label' => 'Дата перевода'], ['label' => 'Номер платёжки']],
            'exits' => [['Переведено поставщику', 'staff', 'payment_check']],
        ];

        // Альфа на письмо о покупке отвечает когда как и не на deal@ (18.10.2026, владелец: «убрать срок шага») — 50 сделок
        // горели просроченными. Ждём её без часов.
        $head = $this->head(garage: true);
        $head['claimed']['limit_minutes'] = null;

        return $head
            + ['confirmed' => $this->confirmed('invoice')]
            + $payment
            // Документы у Альфы ходят по-разному (18.10.2026, владелец: «оригинал отдают нам, иногда скан, иногда напрямую
            // покупателю… через интерфейс не реализуешь — обще „передача договора“, а дальше через чат»): один шаг без срока.
            + ['contract' => [
                'name' => 'Передача договора', 'block' => 'signing', 'waits_for' => 'manager',
                'ask_title' => 'Получите договор', 'ask_text' => 'Оплата принята. Договор передаст поставщик или XCar, детали в чате',
                'exits' => [['Договор у покупателя', 'manager', 'closed_won'], ['Договор передан', 'staff', 'closed_won']],
            ]]
            + self::garageSegment()
            + $this->tail();
    }
}
