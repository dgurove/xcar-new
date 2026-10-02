<?php

namespace App\Users;

/**
 * Что открыто модератору сверх основы — черновиков предложений: админ отмечает в ссылке и в карточке человека.
 * Хранится в `users.access` рядом с галками парковки (`Park\Area`), поэтому значения с префиксом: почта CRM и почта
 * парковки — разные стороны. Деньги, сделки, закупки, настройки и люди — только админу, галок для них нет.
 */
enum CrmArea: string
{
    case Mail = 'crm.mail';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Почта: «Из писем» и переписка',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
