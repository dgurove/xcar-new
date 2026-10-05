<?php

namespace App\Workflow\Presets;

/**
 * Поставщик присылает контакты владельца автомобиля, менеджер забирает его сам. Покупатель менеджера платит собственнику
 * по ДКП, который собираем мы, а менеджер нам — подбор (`DealScheme::OwnerDkp`): после ДКП сделка ждёт его оплату.
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
            'owner_contact' => [
                'name' => 'Контакты владельца переданы менеджеру', 'block' => 'pickup', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Заберите автомобиль у владельца',
                'ask_text' => 'Свяжитесь с владельцем автомобиля, подпишите с покупателем ДКП и приложите его. Без подписанного ДКП сделка не закроется',
                'staff_fields' => [['label' => 'Владелец'], ['label' => 'Телефон владельца'], ['label' => 'Адрес автомобиля', 'type' => 'textarea']],
                'exits' => [['Договор приложен', 'manager', 'selection_pay']],
            ],
        ] + $this->selectionSegment('closed_won') + $this->tail();
    }
}
