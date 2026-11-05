<?php

namespace App\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Номер счёта — сквозной в году у каждого продавца свой: ПРАЙМ и ИП — разные лица, у каждого свой журнал.
 * Год продавца блокируется advisory-lock'ом на время транзакции, чтобы два счёта не получили один номер.
 *
 * У счетов парковки ещё и номер документа «как раньше» (`code`, архив «СТОЯНКА», владелец 05.11.2026): день
 * выставления «ГГГГДДММ» и порядковый за день у продавца — «20261108-01», второй за день «20261108-02». Этим номером
 * подписаны и счёт, и его акт; сквозной `number` остаётся внутренним (выписка, файлы старых счетов).
 */
final class Numbering
{
    private const LOCKS = ['prime' => 7_000_000, 'park' => 7_100_000, 'ros' => 7_200_000];

    public static function next(Seller $seller, int $year): int
    {
        DB::statement('select pg_advisory_xact_lock(?)', [self::LOCKS[$seller->value] + $year]);

        return (int) DB::table('billing_invoices')->where('seller', $seller->value)->where('year', $year)->max('number') + 1;
    }

    /** Номер документа парковки за день; вызывать в той же транзакции после `next()` — замок года уже взят. */
    public static function code(Seller $seller, CarbonInterface $day): string
    {
        DB::statement('select pg_advisory_xact_lock(?)', [self::LOCKS[$seller->value] + (int) $day->format('Y')]);
        $prefix = $day->format('Y').$day->format('d').$day->format('m').'-';
        $last = DB::table('billing_invoices')->where('seller', $seller->value)->where('code', 'like', $prefix.'%')->max('code');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 2, '0', STR_PAD_LEFT);
    }
}
