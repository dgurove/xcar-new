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
    // «Реквизиты для счёта», а не «Счёт от поставщика» (04.11.2026, владелец: «звучит, будто ждём счёт от Альфы, а это мы им
    // должны отправить реквизиты»); шаг, где их ещё нет у менеджера, — «Нет реквизитов».
    public const REQUISITES_BLOCK = ['name' => 'Реквизиты для счёта', 'text' => 'Поставщик выставит счёт по данным покупателя, которые мы ему отправим'];

    public function blocks(): array
    {
        $blocks = $this->saleBlocks();
        $blocks['signing']['text'] = self::SIGNING_TEXT;
        // Счёт выставляет Альфа — по реквизитам, что мы ей пришлём (03.11.2026): между согласованием и оплатой.
        $at = array_search('payment', array_keys($blocks), true);
        $blocks = array_slice($blocks, 0, $at, true) + ['requisites' => self::REQUISITES_BLOCK] + array_slice($blocks, $at, null, true);

        return self::withGarageBlocks($blocks);
    }

    public function stages(): array
    {
        $payment = $this->invoiceSegment('contract');
        $payment['invoice']['exits'] = [
            ['Оплатил, документ приложен', 'manager', 'ros_transfer', 'ros'],
            ['Оплатил, документ приложен', 'manager', 'payment_check', 'not_ros'],
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
            'name' => 'Письмо с реквизитами, в гараж', 'block' => 'agreement_garage', 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'requisites',
            'exits' => [['Счёт от поставщика получен', 'staff', 'garage_payment']],
        ]] + $garage;

        return $head
            + ['confirmed' => $confirmed]
            + self::requisites()
            + $payment
            // После оплаты Альфа несколько дней готовит договор, потом запись на подписание (11.11.2026, BMW X6 № 2610041185;
            // начальник: «филиал ответит, что договор готов, письмом; спросить свободные слоты и согласовать с покупателем»).
            + self::signing()
            + $garage
            + $this->tail();
    }

    public const ASKS_REQUISITES = 'Поставщик просит реквизиты';

    public const SIGNING_TEXT = 'Поставщик готовит договор, затем запись на подписание';

    /** Ключ поля «Дата и время подписания» — по нему `{{ signing }}` в письме записи (`MailController::placeholders`). */
    public const SIGNING_FIELD = 'data_i_vremia_podpisaniia';

    /**
     * Подготовка договора (ждём Альфу без срока) → письмо «свободное время» → менеджер выбирает с покупателем → письмо
     * «запись» → подписание: подписывает по-разному, поэтому кнопка и за менеджера, и за нас.
     */
    public static function signing(): array
    {
        return [
            'contract' => [
                'name' => 'Подготовка договора', 'block' => 'signing', 'waits_for' => 'supplier',
                'ask_text' => 'Оплата получена, поставщик готовит договор',
                'exits' => [['Договор готов', 'staff', 'slots']],
            ],
            'slots' => [
                'name' => 'Свободное время для подписания', 'block' => 'signing', 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'letter' => 'slots',
                'ask_text' => 'Договор готов, узнаём у поставщика свободное время для подписания',
                'exits' => [['Поставщик прислал время', 'staff', 'signing_time']],
            ],
            'signing_time' => [
                'name' => 'Время подписания', 'block' => 'signing', 'waits_for' => 'manager', 'limit_minutes' => self::DAY, 'asks' => 'fields',
                'ask_title' => 'Согласуйте время подписания с покупателем', 'ask_text' => 'Свободное время поставщика — в информации по сделке. Впишите, когда может покупатель',
                'fields' => [['label' => 'Дата и время подписания', 'type' => 'datetime', 'key' => self::SIGNING_FIELD]],
                'exits' => [['Время согласовано', 'manager', 'appointment']],
            ],
            'appointment' => [
                'name' => 'Запись на подписание', 'block' => 'signing', 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'letter' => 'appointment',
                'ask_text' => 'Записываем покупателя на подписание у поставщика',
                'exits' => [['Запись подтверждена', 'staff', 'signing']],
            ],
            'signing' => [
                'name' => 'Подписание', 'block' => 'signing', 'waits_for' => 'manager',
                'ask_title' => 'Подписание договора {{ signing }}', 'ask_text' => 'Покупатель подписывает договор у поставщика в назначенное время',
                'exits' => [['Договор подписан', 'manager', 'closed_won'], ['Договор подписан за менеджера', 'staff', 'closed_won']],
            ],
        ];
    }

    public function letters(): array
    {
        return parent::letters() + [
            'slots' => [
                'name' => 'Поставщику: время для подписания',
                'subject' => '{{ claim_ref }}',
                'body' => "Здравствуйте!\n\nПросим сообщить свободное время для записи на подписание договора купли-продажи {{ car }}, VIN {{ vin }} ({{ claim_ref }})\n\nС уважением,\nАндрей Кузнецов",
            ],
            'appointment' => [
                'name' => 'Поставщику: запись на подписание',
                'subject' => '{{ claim_ref }}',
                'body' => "Здравствуйте!\n\nПросим записать покупателя на подписание договора купли-продажи {{ car }}, VIN {{ vin }} ({{ claim_ref }}): {{ signing }}\n\nС уважением,\nАндрей Кузнецов",
            ],
        ];
    }

    /** Данные покупателя (его ход, админ может за него) → письмо с реквизитами (наш ход до отправки) → счёт поставщика. */
    public static function requisites(): array
    {
        return [
            'buyer_data' => [
                'name' => 'Нет реквизитов', 'block' => 'requisites', 'waits_for' => 'manager', 'limit_minutes' => self::DAY, 'asks' => 'buyer',
                'ask_title' => 'Заполните данные покупателя', 'ask_text' => 'Поставщик выставит счёт на покупателя: нужны паспорт со сканом или реквизиты организации',
                'exits' => [['Данные покупателя заполнены', 'manager', 'requisites']],
            ],
            'requisites' => [
                'name' => 'Письмо с реквизитами', 'block' => 'requisites', 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'requisites',
                'ask_text' => 'Данные отправили поставщику, ждём счёт',
                'exits' => [['Счёт от поставщика получен', 'staff', 'invoice']],
            ],
        ];
    }
}
