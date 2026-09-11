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

    public function label(): string
    {
        return match ($this) {
            self::Nothing => 'Только нажать',
            self::Document => 'Приложить документ',
            self::Fields => 'Заполнить поля',
        };
    }
}
