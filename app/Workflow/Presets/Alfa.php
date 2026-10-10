<?php

namespace App\Workflow\Presets;

/**
 * Поставщик выставляет счёт, после оплаты — развилка по месту подписания. «Забираю в гараж» — гаражная ветка.
 *
 * Забирать — только после оплаты (10.10.2026, владелец: «альфастрахование — только с предварительной оплатой»,
 * `vendors.pay_before_pickup`, `Deal::mayPickUp`). С НДС покупатель платит ООО «Р.О.С.» (`DealScheme::Ros`): деньги
 * пришли — сначала переводим их Альфе (наш шаг, ветка `ros`), и только потом Альфа подтверждает оплату.
 */
final class Alfa extends Route
{
    public function blocks(): array
    {
        return self::withGarageBlocks($this->saleBlocks());
    }

    public function stages(): array
    {
        $payment = $this->invoiceSegment('signing_place');
        $payment['invoice']['exits'] = [
            ['Платёжное поручение приложено', 'manager', 'ros_transfer', 'ros'],
            ['Платёжное поручение приложено', 'manager', 'payment_check', 'not_ros'],
        ];
        // Перевод с Р.О.С. — между оплатой и проверкой Альфой: порядок в блоке — порядок пути.
        $payment = ['invoice' => $payment['invoice'], 'ros_transfer' => null, 'payment_check' => $payment['payment_check']];
        $payment['ros_transfer'] = [
            'name' => 'Перевод поставщику с Р.О.С.', 'block' => 'payment', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
            'staff_fields' => [['label' => 'Дата перевода'], ['label' => 'Номер платёжки']],
            'exits' => [['Переведено поставщику', 'staff', 'payment_check']],
        ];

        return $this->head(garage: true)
            + ['confirmed' => $this->confirmed('invoice')]
            + $payment
            + $this->signingSegment('closed_won', intro: 'Оплата принята. ')
            + self::garageSegment()
            + $this->tail();
    }
}
