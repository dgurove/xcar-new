<?php

namespace App\Park;

use App\Billing\Accrual;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Готовый HTML строк таблицы «Наличия» (`x-park.table-row`): полторы сотни строк по пять-шесть компонентов в каждой
 * слабый сервер рисовал больше полусекунды. Строка берётся из кэша, пока не поменялось то, из чего она собрана:
 * сама ТС, её заявки и число веток писем (теги «что не так»), прайс («Нет тарифа»), имя и логотип вендора,
 * парковка, сумма, долг и вид (с парковкой словом или без). Ключ у каждой строки свой: правка одной ТС
 * перерисовывает только её. Все строки — одним чтением кэша.
 */
final class TableRows
{
    /** @param  Collection<int, Vehicle>  $vehicles @return array<int, string> id ТС => HTML строки */
    public static function render(Collection $vehicles, array $totals, array $debts, bool $place): array
    {
        if ($vehicles->isEmpty()) {
            return [];
        }
        $common = Accrual::mark('park_tariffs').'|'.self::templates();
        $keys = $vehicles->mapWithKeys(fn (Vehicle $v) => [$v->id => 'park.row:'.md5(implode('|', [
            $common, $v->id, $v->updated_at?->getTimestamp(), $v->threads_count, $v->waiting_count ?? 0,
            $v->requests->map(fn ($r) => $r->id.':'.$r->updated_at?->getTimestamp())->implode(','),
            $v->vendor?->name, $v->vendor?->logoUrl(), $v->yard?->name,
            json_encode([$totals[$v->id] ?? null, $debts[$v->id] ?? 0, $place]),
        ]))])->all();
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

    /**
     * Шаблоны, из которых собрана строка: поправили вёрстку — ключ другой, иначе до конца дня отдавались бы строки
     * старого вида. Время правки файлов (в образе — время коммита из `git archive`).
     */
    private static function templates(): string
    {
        return once(fn () => implode(',', array_map(fn ($f) => @filemtime(resource_path("views/components/{$f}.blade.php")) ?: 0,
            ['park/table-row', 'park/alerts', 'ui/plate', 'ui/copy-code', 'ui/icon', 'vendor/logo', 'vendor/name'])));
    }
}
