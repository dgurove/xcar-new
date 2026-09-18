<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;

/** Номер счёта — сквозной в году; год блокируется advisory-lock'ом на время транзакции, чтобы два счёта не получили один номер. */
final class Numbering
{
    public static function next(int $year): int
    {
        DB::statement('select pg_advisory_xact_lock(?)', [7_000_000 + $year]);

        return (int) DB::table('billing_invoices')->where('year', $year)->max('number') + 1;
    }
}
