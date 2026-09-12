<?php

namespace App\Workflow\Presets;

/** Поставщик присылает контакты хозяина машины, менеджер забирает её сам. */
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
                'name' => 'Контакты хозяина у менеджера', 'block' => 'pickup', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Свяжитесь с хозяином машины',
                'ask_text' => 'Свяжитесь с хозяином автомобиля и заберите машину. Когда сделка состоится, приложите договор купли-продажи — без него сделка не закрыта.',
                'staff_fields' => [['label' => 'Хозяин машины'], ['label' => 'Телефон хозяина'], ['label' => 'Где стоит машина', 'type' => 'textarea']],
                'exits' => [['Договор приложен', 'manager', 'closed_won']],
            ],
        ] + $this->tail();
    }
}
