<?php

namespace App\Offers;

use App\Cars\HasLabels;
use App\Vendors\DealFormat;

/**
 * Кто получает деньги за ТС и что платят нам (05.10.2026, у страховых по-разному — решает админ при принятии,
 * по умолчанию от формата вендора, `forVendor`):
 * — «Страхователю по ДКП» (Т-Страхование): покупатель менеджера платит собственнику по ДКП, который собираем мы;
 *   менеджер нам — подбор по ссылке, вознаграждение оставляет себе;
 * — «Страховой напрямую» (Альфа бывает): покупатель платит страховой, менеджер нам — подбор по ссылке;
 * — «ПРАЙМ по счёту» (Совкомбанк, Альфа бывает): покупатель платит ПРАЙМ по счёту («Транспортное средство» = закупочная,
 *   «Агентское вознаграждение» = разница), ДКП ПРАЙМ с покупателем, ПРАЙМ платит страховой; ссылки нет, вознаграждение
 *   менеджеру выплачиваем.
 */
enum DealScheme: string
{
    use HasLabels;

    case Prime = 'prime';
    case OwnerDkp = 'owner_dkp';
    case Insurer = 'insurer';

    public function label(): string
    {
        return match ($this) {
            self::Prime => 'ПРАЙМ по счёту',
            self::OwnerDkp => 'Страхователю по ДКП',
            self::Insurer => 'Страховой напрямую',
        };
    }

    /** Менеджер платит нам подбор (разницу) по ссылке, вознаграждение удерживает: покупатель платит не нам. */
    public function paysSelection(): bool
    {
        return $this !== self::Prime;
    }

    /** Кому покупатель отдаёт деньги за ТС — подпись суммы: «Собственнику по ДКП», «Страховой». */
    public function payeeLabel(): ?string
    {
        return match ($this) {
            self::OwnerDkp => 'Собственнику по ДКП',
            self::Insurer => 'Страховой',
            self::Prime => null,
        };
    }

    public static function forVendor(?DealFormat $format): self
    {
        return match ($format) {
            DealFormat::Direct => self::OwnerDkp,
            DealFormat::Commission => self::Prime,
            default => self::Insurer,
        };
    }
}
