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
    public const REQUISITES_BLOCK = ['name' => 'Счёт от поставщика', 'text' => 'Поставщик выставит счёт по данным покупателя'];

    public function blocks(): array
    {
        $blocks = $this->saleBlocks();
        $blocks['signing']['text'] = 'Договор передаст поставщик или XCar, детали в чате';
        // Счёт выставляет Альфа — по реквизитам, что мы ей пришлём (03.11.2026): между согласованием и оплатой.
        $at = array_search('payment', array_keys($blocks), true);
        $blocks = array_slice($blocks, 0, $at, true) + ['requisites' => self::REQUISITES_BLOCK] + array_slice($blocks, $at, null, true);

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

        // «Подтвердили покупку» — Альфа в ответ просит реквизиты для счёта (03.11.2026, владелец: «Ответ поставщика получен»
        // непонятно к чему ведёт, а реквизиты брать неоткуда): покупатель платит ей сам — сначала его данные (у менеджера и у
        // админа), платим мы (Р.О.С., ПРАЙМ) — наши реквизиты сразу. Письмо с реквизитами админ отправляет сам.
        $confirmed = $this->confirmed('invoice');
        $confirmed['exits'] = [
            [self::ASKS_REQUISITES, 'staff', 'buyer_data', 'not_ros'],
            [self::ASKS_REQUISITES, 'staff', 'requisites', 'ros'],
            ['Поставщик сразу выставил счёт', 'staff', 'invoice'],
        ];

        $garage = self::garageSegment();
        $garage['garage_confirmed']['exits'] = [
            [self::ASKS_REQUISITES, 'staff', 'garage_requisites'],
            ['Поставщик сразу выставил счёт', 'staff', 'garage_payment'],
        ];
        $garage = ['garage_confirmed' => $garage['garage_confirmed'], 'garage_requisites' => [
            'name' => 'Реквизиты поставщику, в гараж', 'block' => 'agreement_garage', 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'requisites',
            'exits' => [['Счёт от поставщика получен', 'staff', 'garage_payment']],
        ]] + $garage;

        return $head
            + ['confirmed' => $confirmed]
            + self::requisites()
            + $payment
            // Документы у Альфы ходят по-разному (18.10.2026, владелец: «оригинал отдают нам, иногда скан, иногда напрямую
            // покупателю… через интерфейс не реализуешь — обще „передача договора“, а дальше через чат»): один шаг без срока.
            + ['contract' => [
                'name' => 'Передача договора', 'block' => 'signing', 'waits_for' => 'manager',
                'ask_title' => 'Получите договор', 'ask_text' => 'Оплата принята. Договор передаст поставщик или XCar, детали в чате',
                'exits' => [['Договор у покупателя', 'manager', 'closed_won'], ['Договор передан', 'staff', 'closed_won']],
            ]]
            + $garage
            + $this->tail();
    }

    public const ASKS_REQUISITES = 'Поставщик просит реквизиты';

    /** Данные покупателя (его ход, админ может за него) → письмо с реквизитами (наш ход до отправки) → счёт поставщика. */
    public static function requisites(): array
    {
        return [
            'buyer_data' => [
                'name' => 'Данные покупателя для счёта', 'block' => 'requisites', 'waits_for' => 'manager', 'limit_minutes' => self::DAY, 'asks' => 'buyer',
                'ask_title' => 'Заполните данные покупателя', 'ask_text' => 'Поставщик выставит счёт на покупателя: нужны паспорт со сканом или реквизиты организации',
                'exits' => [['Данные покупателя заполнены', 'manager', 'requisites']],
            ],
            'requisites' => [
                'name' => 'Реквизиты поставщику', 'block' => 'requisites', 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'requisites',
                'ask_text' => 'Данные отправили поставщику, ждём счёт',
                'exits' => [['Счёт от поставщика получен', 'staff', 'invoice']],
            ],
        ];
    }
}
