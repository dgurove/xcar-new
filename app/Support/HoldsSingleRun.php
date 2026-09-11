<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Команда не запускается второй раз рядом с собой: советующая блокировка Postgres на время сеанса. */
trait HoldsSingleRun
{
    protected function holdingSingleRun(string $name, callable $body): int
    {
        $key = crc32($name);
        if (! DB::selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok) {
            $this->warn("{$name} уже идёт");

            return self::SUCCESS;
        }
        try {
            return (int) $body();
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$key]);
        }
    }
}
