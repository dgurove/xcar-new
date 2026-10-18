<?php

namespace App\Offers;

use App\Billing\Seller;
use App\Cars\HasLabels;
use App\Vendors\DealFormat;
use App\Vendors\Vendor;

/**
 * Кто получает деньги за ТС и что платят нам (05.10.2026, у страховых по-разному — решает админ при принятии,
 * по умолчанию от формата вендора, `forVendor`):
 * — «Страхователю по ДКП» (Т-Страхование): покупатель менеджера платит собственнику по ДКП, который собираем мы;
 *   менеджер нам — подбор по ссылке, вознаграждение оставляет себе;
 * — «Страховой напрямую» (Альфа бывает): покупатель платит страховой, менеджер нам — подбор по ссылке;
 * — «ПРАЙМ по счёту» (Совкомбанк, Альфа бывает): покупатель платит ПРАЙМ по счёту («Транспортное средство» = закупочная,
 *   «Агентское вознаграждение» = разница), ДКП ПРАЙМ с покупателем, ПРАЙМ платит страховой; ссылки нет, вознаграждение
 *   менеджеру выплачиваем;
 * — «Р.О.С. по счёту» (10.10.2026, АльфаСтрахование с НДС): то же, что ПРАЙМ, только продаёт ООО «Р.О.С.» — оно и
 *   переводит Альфе. У Альфы (`vendors.pay_before_pickup`) схема по умолчанию — по НДС предложения (`forOffer`): с НДС
 *   Р.О.С., без — «Страховой напрямую».
 */
enum DealScheme: string
{
    use HasLabels;

    case Prime = 'prime';
    case OwnerDkp = 'owner_dkp';
    case Insurer = 'insurer';
    case Ros = 'ros';

    public function label(): string
    {
        return match ($this) {
            self::Prime => 'ПРАЙМ по счёту',
            self::OwnerDkp => 'Страхователю по ДКП',
            self::Insurer => 'Страховой напрямую',
            self::Ros => 'Р.О.С. по счёту',
        };
    }

    /** Покупатель платит за ТС нашей конторе по счёту (ПРАЙМ или Р.О.С.), она — страховой. */
    public function paysCar(): bool
    {
        return $this === self::Prime || $this === self::Ros;
    }

    /** Чей счёт за ТС и договор купли-продажи. */
    public function seller(): Seller
    {
        return $this === self::Ros ? Seller::Ros : Seller::Prime;
    }

    /** Менеджер платит нам подбор (разницу) по ссылке, вознаграждение удерживает: покупатель платит не нам. */
    public function paysSelection(): bool
    {
        return ! $this->paysCar();
    }

    /** Кому покупатель отдаёт деньги за ТС — подпись суммы: «Собственнику по ДКП», «Страховой». */
    public function payeeLabel(): ?string
    {
        return match ($this) {
            self::OwnerDkp => 'Собственнику по ДКП',
            self::Insurer => 'Страховой',
            self::Prime, self::Ros => null,
        };
    }

    /** То же глаголом для менеджера — что ему сделать (06.10.2026, владелец: «прям явно: оплатить собственнику по ДКП»). */
    public function payLabel(): ?string
    {
        return match ($this) {
            self::OwnerDkp => 'Оплатить собственнику по ДКП',
            self::Insurer => 'Оплатить страховой',
            self::Prime, self::Ros => null,
        };
    }

    public static function forVendor(?DealFormat $format): self
    {
        // «Страховой напрямую» — только выбором админа: по умолчанию счёт ничей не уходит менеджеру сам (ревью 05.10.2026).
        return $format === DealFormat::Direct ? self::OwnerDkp : self::Prime;
    }

    /**
     * Схема по умолчанию для предложения. АльфаСтрахование (`vendors.pay_before_pickup`, 10.10.2026, владелец): с НДС —
     * через Р.О.С., без — покупатель платит Альфе напрямую, подбор нам по ссылке.
     */
    public static function forOffer(Offer $offer): self
    {
        if ($offer->vendor?->pay_before_pickup) {
            return $offer->prices_include_vat ? self::Ros : self::Insurer;
        }

        return self::forVendor($offer->vendor?->deal_format);
    }

    /**
     * Схемы в шторке: Р.О.С. — только у Альфы и только у предложения с НДС (18.10.2026, владелец: 20 сделок без НДС на
     * Р.О.С. — «ошибка»: его счёт с НДС 22 %). @return list<self>
     */
    public static function casesFor(?Vendor $vendor, ?Offer $offer = null): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s !== self::Ros || ($vendor?->pay_before_pickup && (! $offer || $offer->prices_include_vat))));
    }

    /**
     * Схема сделки, которую заводит «Отдать» (`Give`): выбранная в шторке, если её можно выбрать у этого вендора
     * (оптом — только ПРАЙМ, Р.О.С. — только у Альфы), иначе по умолчанию предложения.
     */
    public static function resolve(Offer $offer, ?self $requested): self
    {
        $default = self::forOffer($offer);
        if (! $requested || ! self::choiceFor($offer->vendor?->deal_format)) {
            return $default;
        }

        return in_array($requested, self::casesFor($offer->vendor, $offer), true) ? $requested : $default;
    }

    /** Схема на выбор в шторках «Принять» и «Деньги»: оптом схема одна — ПРАЙМ, выбирать нечего (null). */
    public static function choiceFor(?DealFormat $format, ?self $current = null): ?self
    {
        return $format === DealFormat::Wholesale ? null : ($current ?? self::forVendor($format));
    }
}
