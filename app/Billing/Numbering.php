<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Номер счёта — сквозной в году у каждого продавца свой: ПРАЙМ и ИП — разные лица, у каждого свой журнал.
 * Год продавца блокируется advisory-lock'ом на время транзакции, чтобы два счёта не получили один номер.
 */
final class Numbering
{
    private const LOCKS = ['prime' => 7_000_000, 'park' => 7_100_000];

    public static function next(Seller $seller, int $year): int
    {
        DB::statement('select pg_advisory_xact_lock(?)', [self::LOCKS[$seller->value] + $year]);

        return (int) DB::table('billing_invoices')->where('seller', $seller->value)->where('year', $year)->max('number') + 1;
    }
}
