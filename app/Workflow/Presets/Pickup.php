<?php

namespace App\Workflow\Presets;

/**
 * Вывоз машины от страхователя. Менеджера здесь нет ни на одном этапе: он
 * покупает машину, а забираем её мы. Ход везде наш, ось — где машина, и её
 * же читает посетитель в объявлении; блоки названы теми же словами.
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
            'at_owner' => ['name' => 'Машина у владельца', 'text' => 'Договариваемся с владельцем и готовим документы.'],
            'collecting' => ['name' => 'Забираем машину', 'text' => 'Выезжаем за машиной и проводим осмотр.'],
            'moving' => ['name' => 'Перемещаем машину', 'text' => 'Машина едет на нашу площадку.'],
            'at_yard' => ['name' => 'Машина у нас', 'text' => 'Машина на нашей площадке.'],
        ];
    }

    public function stages(): array
    {
        return [
            'call_owner' => [
                'name' => 'Звоним страхователю', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => self::DAY, 'car_place' => 'owner',
                'staff_fields' => [['label' => 'Страхователь'], ['label' => 'Телефон'], ['label' => 'Где стоит машина', 'type' => 'textarea']],
                'exits' => [['Дозвонились', 'staff', 'papers_way']],
            ],
            'papers_way' => [
                'name' => 'Как оформляем документы', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Едем лично', 'staff', 'meeting'], ['Отправляем СДЭКом', 'staff', 'papers_sent']],
            ],
            'meeting' => [
                'name' => 'Встреча со страхователем', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Дата встречи'], ['label' => 'Адрес встречи', 'type' => 'textarea']],
                'exits' => [['Документы подписаны', 'staff', 'pickup_date']],
            ],
            'papers_sent' => [
                'name' => 'Договор комиссии и акт отправлены СДЭКом', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY,
                'staff_fields' => [['label' => 'Адрес получателя', 'type' => 'textarea'], ['label' => 'Трек отправления']],
                'exits' => [['Документы вернулись подписанными', 'staff', 'pickup_date']],
            ],
            'pickup_date' => [
                'name' => 'Назначаем дату вывоза', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'staff_fields' => [['label' => 'Дата вывоза'], ['label' => 'Кто вывозит']],
                'exits' => [['Дата назначена', 'staff', 'pickup']],
            ],
            'pickup' => [
                'name' => 'Вывоз и осмотр', 'block' => 'collecting', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY, 'car_place' => 'moving',
                'staff_fields' => [['label' => 'Что показал осмотр', 'type' => 'textarea']],
                'exits' => [['Машина забрана, осмотр проведён', 'staff', 'transfer']],
            ],
            'transfer' => [
                'name' => 'Перемещение в город присутствия', 'block' => 'moving', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY, 'car_place' => 'moving',
                'staff_fields' => [['label' => 'Город присутствия'], ['label' => 'Площадка']],
                'exits' => [['Машина на площадке', 'staff', 'at_yard']],
            ],
            'at_yard' => ['name' => 'Машина у нас', 'block' => 'at_yard', 'waits_for' => 'nobody', 'car_place' => 'ours'],
        ];
    }
}
