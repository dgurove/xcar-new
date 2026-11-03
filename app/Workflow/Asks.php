<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/** Что менеджер прикладывает к ответу, прежде чем нажать кнопку. */
enum Asks: string
{
    use HasLabels;

    case Nothing = 'nothing';
    case Document = 'document';
    case Fields = 'fields';
    // Данные покупателя (03.11.2026, Альфа: «пришлите реквизиты для счёта»): форма покупателя сделки — у менеджера и у
    // админа, шаг проходит сам, когда данные со сканом есть (`AdvanceOnBuyerData`).
    case Buyer = 'buyer';

    public function label(): string
    {
        return match ($this) {
            self::Nothing => 'Только нажать',
            self::Document => 'Приложить документ',
            self::Fields => 'Заполнить поля',
            self::Buyer => 'Данные покупателя',
        };
    }
}
