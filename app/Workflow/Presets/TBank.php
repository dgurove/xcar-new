<?php

namespace App\Workflow\Presets;

/**
 * Поставщик присылает контакты владельца автомобиля, менеджер связывается с ним, забирает автомобиль и прикладывает
 * подписанный ДКП. Покупатель менеджера платит собственнику по ДКП, который собираем мы, а менеджер нам — подбор
 * (`DealScheme::OwnerDkp`): после ДКП сделка ждёт его оплату, с этого шага и срок счёта (`StartSelectionDue`).
 */
final class TBank extends Route
{
    public function blocks(): array
    {
        return $this->saleBlocks();
    }

    public function stages(): array
    {
        return $this->head() + ['confirmed' => $this->confirmed('owner_contact')] + [
            // Порядок дела (06.10.2026, владелец: «сначала он отмечает, что связался со страхователем, потом — что
            // забрал машину»): связался → забрал → подписанный ДКП. Покупатель и ДКП готовятся параллельно — чек-листом
            // задачи (`cabinet.deals.step`), подписывают при передаче.
            'owner_contact' => [
                'name' => 'Контакты владельца переданы менеджеру', 'block' => 'pickup', 'waits_for' => 'manager', 'limit_minutes' => self::DAY,
                'ask_title' => 'Свяжитесь с владельцем',
                'ask_text' => 'Свяжитесь с владельцем автомобиля и договоритесь о передаче',
                'staff_fields' => [['label' => 'Владелец'], ['label' => 'Телефон владельца'], ['label' => 'Адрес автомобиля', 'type' => 'textarea']],
                'exits' => [['Связался с владельцем', 'manager', 'buyer_pickup']],
            ],
            'buyer_pickup' => [
                'name' => 'Менеджер забирает автомобиль', 'block' => 'pickup', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY,
                'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Заберите автомобиль у владельца',
                'exits' => [['Автомобиль забрал', 'manager', 'dkp_signed'], ['Автомобиль передан', 'staff', 'dkp_signed']],
            ],
            'dkp_signed' => [
                'name' => 'Подписанный договор', 'block' => 'pickup', 'waits_for' => 'manager', 'limit_minutes' => 2 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Приложите подписанный договор',
                'ask_text' => 'Приложите ДКП, подписанный собственником и покупателем. Без него сделка не закроется',
                'exits' => [['Договор приложен', 'manager', 'selection_pay']],
            ],
        ] + $this->selectionSegment('closed_won') + $this->tail();
    }
}
