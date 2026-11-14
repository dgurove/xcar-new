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
 * С 12.11.2026 (владелец, DAF XF Корабельникова: цены с НДС, а приняли через ПРАЙМ — галку не увидели) ПРАЙМ или Р.О.С.
 * выбирают у любого поставщика: где по умолчанию ПРАЙМ, цены с НДС — Р.О.С.; опт (Каркаде, всегда «с НДС») — ПРАЙМ, Р.О.С.
 * выбором. Сменили в сделке — счёт перевыставляется, договор перенумеровывается (`ChangeDealScheme`).
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
            // Что за договор — словами (03.11.2026, владелец: «варианты понятнее назвать, чтобы не тупили»; было «ПРАЙМ по
            // счёту», «Страховой напрямую» — по Land Cruiser 781R договор напрямую, а стоял ПРАЙМ).
            self::Prime => 'Через ПРАЙМ: ПРАЙМ продаёт покупателю',
            self::OwnerDkp => 'ДКП: собственник продаёт покупателю',
            self::Insurer => 'Напрямую: страховая продаёт покупателю',
            self::Ros => 'Через Р.О.С.: Р.О.С. продаёт покупателю, с НДС',
        };
    }

    /** Коротко — надпись сегмента «Кто продаёт» в «Деньгах» (14.11.2026). */
    public function short(): string
    {
        return match ($this) {
            self::Prime => 'ПРАЙМ',
            self::Ros => 'Р.О.С.',
            self::Insurer => 'Напрямую',
            self::OwnerDkp => 'ДКП',
        };
    }

    /** Кто кому платит — одной фразой в шторке «Схема сделки» (03.11.2026, владелец: «кто нажал ПРАЙМ, не понял»). */
    public function hint(): string
    {
        return match ($this) {
            self::Insurer => 'Покупатель платит страховой сам, нам — подбор по ссылке',
            self::OwnerDkp => 'Покупатель платит собственнику по ДКП, нам — подбор по ссылке',
            self::Prime => 'Покупатель платит ПРАЙМ по счёту, ПРАЙМ — поставщику, ДКП с ПРАЙМ',
            self::Ros => 'Покупатель платит Р.О.С. по счёту, Р.О.С. — поставщику, ДКП с Р.О.С.',
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
        $format = $offer->vendor?->deal_format;
        // Совкомбанк (по комиссии) — всегда через ПРАЙМ (14.11.2026, владелец): ПРАЙМ — его комиссионер.
        if ($format === DealFormat::Commission) {
            return self::Prime;
        }
        $default = self::forVendor($format);

        // Цены с НДС — продаёт Р.О.С. (12.11.2026); опт — ПРАЙМ при любом НДС.
        return $default === self::Prime && $offer->prices_include_vat && $format !== DealFormat::Wholesale ? self::Ros : $default;
    }

    /**
     * Схемы в шторке. ПРАЙМ и Р.О.С. — у всех (12.11.2026, владелец: «либо прайм, либо рос — в любом случае»); не по НДС —
     * с переспросом (`unusual`; 18.10.2026 20 сделок без НДС на Р.О.С. были ошибкой — его счёт с НДС 22 %). Опт — только
     * эти две. @return list<self>
     */
    public static function casesFor(?Vendor $vendor, ?Offer $offer = null): array
    {
        // По комиссии (Совкомбанк) — только ПРАЙМ: договор комиссии у него с ПРАЙМ (14.11.2026, владелец: «всегда через прайм»).
        if ($vendor?->deal_format === DealFormat::Commission) {
            return [self::Prime];
        }
        $alfa = (bool) $vendor?->pay_before_pickup;
        $wholesale = $vendor?->deal_format === DealFormat::Wholesale;
        $cases = array_filter(self::cases(), fn (self $s) => match ($s) {
            self::Prime, self::Ros => true,
            // ДКП собственника у Альфы не бывает — лишний вариант путал (03.11.2026).
            self::OwnerDkp => ! $alfa && ! $wholesale,
            self::Insurer => ! $wholesale,
        });
        // Умолчание — первым, ПРАЙМ — последним, если он не умолчание (03.11.2026: ПРАЙМ стоял первым, его и принимали).
        $default = $offer ? self::forOffer($offer) : ($alfa ? self::Insurer : self::forVendor($vendor?->deal_format));
        usort($cases, fn (self $a, self $b) => self::rank($a, $default) <=> self::rank($b, $default));

        return array_values($cases);
    }

    private static function rank(self $s, self $default): int
    {
        $pair = [self::Prime, self::Ros];

        return match (true) {
            $s === $default => 0,
            // Умолчание — ПРАЙМ или Р.О.С.: второй из пары — сразу за ним, переключиться рядом (12.11.2026).
            in_array($default, $pair, true) && in_array($s, $pair, true) => 1,
            $s === self::Prime => 9,
            $s === self::Ros => 8,
            default => 2 + array_search($s, self::cases(), true),
        };
    }

    /**
     * Не умолчание — переспросить (03.11.2026): «У АльфаСтрахование обычно „Напрямую…“. Точно „Через ПРАЙМ…“?». null — не надо.
     */
    public static function unusual(Offer $offer, self $chosen): ?string
    {
        $default = self::forOffer($offer);
        if ($chosen === $default || $offer->vendor?->deal_format === DealFormat::Wholesale) {
            return null;
        }
        // ПРАЙМ ↔ Р.О.С. — дело в НДС цен (12.11.2026).
        if ($chosen === self::Prime && $offer->prices_include_vat) {
            return 'Цены с НДС — обычно через Р.О.С. Точно продавать через ПРАЙМ?';
        }
        if ($chosen === self::Ros && ! $offer->prices_include_vat) {
            return 'Цены без НДС, а счёт Р.О.С. — с НДС 22 %. Точно через Р.О.С.?';
        }

        return 'У поставщика «'.($offer->vendor?->name ?? '').'» обычно «'.$default->label().'». Точно «'.$chosen->label().'»?';
    }

    /**
     * Схема сделки, которую заводит «Отдать» (`Give`): выбранная в шторке, если её можно выбрать у этого вендора
     * (`casesFor`), иначе по умолчанию предложения.
     */
    public static function resolve(Offer $offer, ?self $requested): self
    {
        $default = self::forOffer($offer);
        if (! $requested) {
            return $default;
        }

        return in_array($requested, self::casesFor($offer->vendor, $offer), true) ? $requested : $default;
    }

    /** Схема на выбор в шторках «Принять» и «Деньги» (оптом с 12.11.2026 тоже — ПРАЙМ или Р.О.С.). */
    public static function choiceFor(?DealFormat $format, ?self $current = null): ?self
    {
        return $current ?? self::forVendor($format);
    }
}
