<?php

namespace App\Workflow\Presets;

/**
 * Вывоз автомобиля от страхователя. Менеджера здесь нет ни на одном этапе:
 * он покупает автомобиль, а забираем его мы. Ход везде наш, ось — где
 * автомобиль, и её же читает посетитель в объявлении; блоки названы теми же
 * словами. «Страхователь» — только в наших этапах, наружу слово не выходит.
 *
 * Способ оформления документов — выбор, а не правило по регионам: в Москве,
 * Питере и Екатеринбурге обычно едем сами, но правило по городу связало бы
 * руки в обе стороны.
 */
final class Pickup extends Route
{
    public function blocks(): array
    {
        return [
            'at_owner' => ['name' => 'Автомобиль у владельца', 'text' => 'Согласовываем передачу с владельцем и готовим документы.'],
            'collecting' => ['name' => 'Вывоз автомобиля', 'text' => 'Выезжаем за автомобилем и проводим осмотр.'],
            'moving' => ['name' => 'Перегон на площадку', 'text' => 'Автомобиль перемещается на нашу площадку.'],
            'at_yard' => ['name' => 'Автомобиль на площадке', 'text' => 'Автомобиль находится на нашей площадке.'],
        ];
    }

    public function stages(): array
    {
        return [
            'call_owner' => [
                'name' => 'Связь со страхователем', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => self::DAY, 'car_place' => 'owner',
                'staff_fields' => [['label' => 'Страхователь'], ['label' => 'Телефон'], ['label' => 'Адрес автомобиля', 'type' => 'textarea']],
                'exits' => [['Связались со страхователем', 'staff', 'papers_way']],
            ],
            'papers_way' => [
                'name' => 'Способ оформления документов', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Личная встреча', 'staff', 'meeting'], ['Отправка курьерской службой', 'staff', 'papers_sent']],
            ],
            'meeting' => [
                'name' => 'Встреча со страхователем', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Дата встречи'], ['label' => 'Адрес встречи', 'type' => 'textarea']],
                'exits' => [['Документы подписаны', 'staff', 'pickup_date']],
            ],
            'papers_sent' => [
                'name' => 'Договор комиссии и акт отправлены', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY,
                'staff_fields' => [['label' => 'Адрес получателя', 'type' => 'textarea'], ['label' => 'Трек-номер отправления']],
                'exits' => [['Подписанные документы получены', 'staff', 'pickup_date']],
            ],
            'pickup_date' => [
                'name' => 'Назначение даты вывоза', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'staff_fields' => [['label' => 'Дата вывоза'], ['label' => 'Исполнитель']],
                'exits' => [['Дата назначена', 'staff', 'pickup']],
            ],
            'pickup' => [
                'name' => 'Вывоз и осмотр', 'block' => 'collecting', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY, 'car_place' => 'moving',
                'staff_fields' => [['label' => 'Результат осмотра', 'type' => 'textarea']],
                'exits' => [['Автомобиль вывезен, осмотр проведён', 'staff', 'transfer']],
            ],
            'transfer' => [
                'name' => 'Перегон в город присутствия', 'block' => 'moving', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY, 'car_place' => 'moving',
                'staff_fields' => [['label' => 'Город'], ['label' => 'Площадка']],
                'exits' => [['Автомобиль на площадке', 'staff', 'at_yard']],
            ],
            'at_yard' => ['name' => 'Автомобиль на площадке', 'block' => 'at_yard', 'waits_for' => 'nobody', 'car_place' => 'ours'],
        ];
    }
}
