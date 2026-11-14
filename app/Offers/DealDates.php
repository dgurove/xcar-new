<?php

namespace App\Offers;

use Illuminate\Support\Carbon;

/**
 * «Продано» и «Оплачено» сделки (14.11.2026, владелец: во «Все» — когда нам за машину заплатили и когда мы заплатили
 * поставщику; «когда плата не через нас, а поставщику — одинаковые даты, это норм»). Одна дверь для столбцов «Все», их
 * сортировки и правки нажатием (`SetDealDate`):
 * — «Продано» — через нас (ПРАЙМ / Р.О.С., ДКП собственника — наши счета) `deals.paid_in_at` (`StampPaidIn`), мимо
 *   нас — `prepaid_at` (поставщик получил оплату);
 * — «Оплачено» — платим мы (ПРАЙМ / Р.О.С., гараж «платим мы») `supplier_paid_at`, платит менеджер страховой — `prepaid_at`;
 *   ДКП собственника — нет (поставщику платит не нам и не мы).
 */
final class DealDates
{
    public const SOLD = 'sold';

    public const PAID = 'paid';

    /**
     * Деньги за эту машину идут через нас (тогда «Продано» — по нашим счетам): ПРАЙМ / Р.О.С. — за ТС, ДКП собственника — подбор.
     * По схеме, без запроса: строка «Все» на сотни машин, и сортировка в SQL считает так же (`sortSql`).
     */
    public static function throughUs(Deal $deal): bool
    {
        return $deal->schemeOf()->paysCar() || $deal->scheme === DealScheme::OwnerDkp;
    }

    /** Колонка «Продано» этой сделки. */
    public static function soldColumn(Deal $deal): string
    {
        return self::throughUs($deal) ? 'paid_in_at' : 'prepaid_at';
    }

    /** Колонка «Оплачено»; null — не нужно (ДКП собственника). */
    public static function paidColumn(Deal $deal): ?string
    {
        return match (true) {
            $deal->schemeOf()->paysCar() || $deal->isGarageUs() => 'supplier_paid_at',
            $deal->scheme === DealScheme::OwnerDkp => null,
            default => 'prepaid_at',
        };
    }

    public static function sold(Deal $deal): ?Carbon
    {
        return $deal->{self::soldColumn($deal)};
    }

    public static function paid(Deal $deal): ?Carbon
    {
        return ($c = self::paidColumn($deal)) ? $deal->{$c} : null;
    }

    /**
     * Порядок «Все» по дате: последняя идущая или состоявшаяся сделка машины, колонка — как `soldColumn` / `paidColumn`.
     */
    public static function sortSql(string $what): string
    {
        $expr = $what === self::SOLD
            ? "case when d.scheme in ('prime', 'ros', 'owner_dkp') then d.paid_in_at else d.prepaid_at end"
            : "case when d.scheme in ('prime', 'ros') or d.garage_payer = 'us' then d.supplier_paid_at when d.scheme = 'owner_dkp' then null else d.prepaid_at end";

        return "(select {$expr} from deals d where d.offer_id = offers.id and d.state in ('active', 'done') order by d.id desc limit 1)";
    }
}
