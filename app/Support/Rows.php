<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Перенос строк с одной записи на другую при объединении двойников (`MergeOffers`, `MergeVehicles`). */
final class Rows
{
    /** @return list<array{0: string, 1: string}> таблица и колонка каждого внешнего ключа на `$table` */
    public static function referencing(string $table): array
    {
        return collect(DB::select(<<<'SQL'
            select c.conrelid::regclass::text as tbl, a.attname as col
            from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey)
            where c.contype = 'f' and c.confrelid = ?::regclass
            SQL, [$table]))->map(fn ($r) => [$r->tbl, $r->col])->all();
    }

    /**
     * Строки `$from` — к `$to`. Упёрлись в уникальность (тот же менеджер подтвердил оба, та же бумага в ту же сторону) —
     * построчно, и строка, у которой у `$to` уже есть пара, уходит: побеждает оставшаяся запись.
     */
    public static function repoint(string $table, string $column, int $from, int $to, string $where = 'true'): void
    {
        try {
            DB::transaction(fn () => DB::update("update {$table} set {$column} = ? where {$column} = ? and {$where}", [$to, $from]));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            foreach (DB::select("select ctid::text as row from {$table} where {$column} = ? and {$where}", [$from]) as $r) {
                try {
                    DB::transaction(fn () => DB::update("update {$table} set {$column} = ? where ctid = ?::tid", [$to, $r->row]));
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23505') {
                        throw $e;
                    }
                    DB::delete("delete from {$table} where ctid = ?::tid", [$r->row]);
                }
            }
        }
    }
}
