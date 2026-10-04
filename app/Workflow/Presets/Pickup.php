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
 *
 * Страхователь, адрес, дата вывоза, осмотр и площадка живут на стоянке
 * (заявка на эвакуацию, осмотр, ТС) — полей для них здесь нет, этапы
 * двигает сама стоянка (`Park\Listeners\SyncOffer`).
 *
 * Куда везём (04.10.2026, `Offers\Destination`) — ветка исхода «Вывоз и осмотр»: на парковку — прежний путь; к
 * менеджеру и к нам вне парковки — «Забрал» жмёт ответственный за вывоз (менеджер, а без него мы), и ТС встаёт
 * «Стоит у менеджера» / «Стоит у нас». Дату, адрес и контакт вписываем на «Дата назначена» — их видит и менеджер.
 */
final class Pickup extends Route
{
    public function blocks(): array
    {
        return [
            'at_owner' => ['name' => 'Автомобиль у владельца', 'text' => 'Согласовываем передачу с владельцем и готовим документы.'],
            'collecting' => ['name' => 'Вывоз автомобиля', 'text' => 'Выезжаем за автомобилем и проводим осмотр.'],
            'moving' => ['name' => 'Перегон на парковку', 'text' => 'Автомобиль перемещается на нашу парковку.'],
            'at_yard' => ['name' => 'Автомобиль на парковке', 'text' => 'Автомобиль находится на нашей парковке.'],
            'at_keeper' => ['name' => 'Стоит у менеджера', 'text' => 'Автомобиль вывез и держит у себя менеджер.'],
            'at_ours' => ['name' => 'Стоит у нас', 'text' => 'Автомобиль стоит у нас, не на парковке.'],
        ];
    }

    public function stages(): array
    {
        return [
            'call_owner' => [
                'name' => 'Связь со страхователем', 'block' => 'at_owner', 'waits_for' => 'us', 'limit_minutes' => self::DAY, 'car_place' => 'owner',
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
                'exits' => [['Дата назначена', 'staff', 'pickup']],
            ],
            'pickup' => [
                'name' => 'Вывоз и осмотр', 'block' => 'collecting', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY, 'car_place' => 'moving',
                'staff_fields' => [['label' => 'Дата вывоза', 'type' => 'date'], ['label' => 'Адрес', 'type' => 'textarea'], ['label' => 'Контакт']],
                'exits' => [
                    ['Автомобиль вывезен, осмотр проведён', 'staff', 'transfer', 'yard'],
                    ['Забрал', 'keeper', 'at_keeper', 'keeper'],
                    ['Забрал', 'keeper', 'at_ours', 'ours'],
                ],
            ],
            'transfer' => [
                'name' => 'Перегон в город присутствия', 'block' => 'moving', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY, 'car_place' => 'moving',
                'exits' => [['Автомобиль на парковке', 'staff', 'at_yard']],
            ],
            'at_yard' => ['name' => 'Автомобиль на парковке', 'block' => 'at_yard', 'waits_for' => 'nobody', 'car_place' => 'ours'],
            'at_keeper' => ['name' => 'Стоит у менеджера', 'block' => 'at_keeper', 'waits_for' => 'nobody', 'car_place' => 'keeper'],
            'at_ours' => ['name' => 'Стоит у нас', 'block' => 'at_ours', 'waits_for' => 'nobody', 'car_place' => 'with_us'],
        ];
    }
}
