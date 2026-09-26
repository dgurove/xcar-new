<?php

namespace App\Park;

use App\Billing\Accrual;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Готовый HTML строк таблицы «Наличия» (`x-park.table-row`): полторы сотни строк по пять-шесть компонентов в каждой
 * слабый сервер рисовал больше полусекунды. Строка берётся из кэша, пока не поменялось то, из чего она собрана:
 * ТС, её события, прайс и вендор (отпечаток начислений), ветки писем и заявки (теги «что не так»), логотипы, а
 * также её сумма, долг и вид (с парковкой словом или без) — они прямо в ключе. Все строки — одним чтением кэша.
 */
final class TableRows
{
    /** @param  Collection<int, Vehicle>  $vehicles @return array<int, string> id ТС => HTML строки */
    public static function render(Collection $vehicles, array $totals, array $debts, bool $place): array
    {
        if ($vehicles->isEmpty()) {
            return [];
        }
        $fingerprint = implode('|', [Accrual::fingerprint(), Accrual::mark('mail_threads'), Accrual::mark('park_requests'), Accrual::mark('media', 'id')]);
        $keys = $vehicles->mapWithKeys(fn (Vehicle $v) => [$v->id => 'park.row:'.md5($fingerprint.'|'.$v->id.'|'.json_encode([$totals[$v->id] ?? null, $debts[$v->id] ?? 0, $place]))])->all();
        $cached = Cache::many(array_values($keys));
        $rows = $fresh = [];
        foreach ($vehicles as $v) {
            $html = $cached[$keys[$v->id]] ?? null;
            if ($html === null) {
                $html = view('components.park.table-row', ['vehicle' => $v, 'total' => $totals[$v->id] ?? null, 'debt' => $debts[$v->id] ?? 0, 'place' => $place])->render();
                $fresh[$keys[$v->id]] = $html;
            }
            $rows[$v->id] = $html;
        }
        if ($fresh) {
            Cache::putMany($fresh, now()->endOfDay());
        }

        return $rows;
    }
}
