<?php

namespace App\Billing;

use App\Cars\HasLabels;

enum PartyKind: string
{
    use HasLabels;

    case Company = 'company';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Юрлицо',
            self::Person => 'Физлицо',
        };
    }
}
