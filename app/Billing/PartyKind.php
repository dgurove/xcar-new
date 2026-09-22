<?php

namespace App\Billing;

use App\Cars\HasLabels;

/** Кто контрагент: юрлицо, ИП, самозанятый или человек — от вида зависят реквизиты в счёте и что нужно для выплаты. */
enum PartyKind: string
{
    use HasLabels;

    case Company = 'company';
    case Entrepreneur = 'ip';
    case SelfEmployed = 'npd';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Юрлицо',
            self::Entrepreneur => 'ИП',
            self::SelfEmployed => 'Самозанятый',
            self::Person => 'Физлицо',
        };
    }

    /** Реквизиты компании: ИНН, КПП, ОГРН, адрес, руководитель. */
    public function isCompany(): bool
    {
        return $this === self::Company;
    }

    /** Человек с паспортом: самозанятый и физлицо. */
    public function isPerson(): bool
    {
        return $this === self::Person || $this === self::SelfEmployed;
    }
}
