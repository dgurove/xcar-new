<?php

namespace App\Offers;

use App\Cars\HasLabels;

/** Признаки ТС из письма поставщика: кредитная, лизинговая, в залоге, юрлицо, ограничения, ключи ещё у собственника. */
enum Flag: string
{
    use HasLabels;

    case Credit = 'credit';
    case Leasing = 'leasing';
    case Pledged = 'pledged';
    case LegalOwner = 'legal_owner';
    case Restricted = 'restricted';
    case KeysPending = 'keys_pending';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Кредитная',
            self::Leasing => 'Лизинг',
            self::Pledged => 'В залоге',
            self::LegalOwner => 'Юрлицо',
            self::Restricted => 'Ограничения',
            self::KeysPending => 'Ключи не переданы',
        };
    }

    /** @param  list<string>|null  $values
     * @return list<self> */
    public static function fromList(?array $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => self::tryFrom((string) $v), $values ?? [])));
    }
}
