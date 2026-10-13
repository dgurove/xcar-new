<?php

namespace App\Billing;

use App\Cars\HasLabels;

enum PaymentSource: string
{
    use HasLabels;

    case Bank = 'bank';
    case Cash = 'cash';
    case Acquiring = 'acquiring';
    case Offset = 'offset';
    /** Зачёт разовой оплаты по ссылке с машиной сделки (`ApplyDealCredits`): деньги пришли по другому счёту. */
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Банк',
            self::Cash => 'Наличные',
            self::Acquiring => 'Эквайринг',
            self::Offset => 'Зачёт',
            self::Credit => 'Зачёт по ссылке',
        };
    }

    /**
     * Не деньги по этому счёту, а зачёт: удержанное вознаграждение и оплата по другой (ручной) ссылке. Такой счёт можно
     * аннулировать и перевыставить — зачёт гаснет вместе с ним и ляжет в новый. @return list<string>
     */
    public static function soft(): array
    {
        return [self::Offset->value, self::Credit->value];
    }

    /** Откуда деньги в ручной оплате: зачёт ставит только система, руками его не выбрать. @return array<string, string> */
    public static function manual(): array
    {
        return array_diff_key(self::options(), array_flip(self::soft()));
    }

    public function isSoft(): bool
    {
        return in_array($this->value, self::soft(), true);
    }

    /** Иконка у строки оплаты; у эквайринга точнее — способ (`PayMethod::icon`). */
    public function icon(): string
    {
        return match ($this) {
            self::Bank => 'bank',
            self::Cash => 'cash',
            self::Acquiring => 'card',
            self::Offset => 'offset',
            self::Credit => 'offset',
        };
    }
}
