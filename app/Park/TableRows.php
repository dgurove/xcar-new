<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Park\Actions\FillFromDocs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Готовый HTML строк таблицы «Наличия» (`x-park.table-row`): полторы сотни строк по пять-шесть компонентов в каждой
 * слабый сервер рисовал больше полусекунды. Строка берётся из кэша, пока не поменялось то, из чего она собрана:
 * сама ТС, её заявки и число веток писем (теги «что не так»), прайс («Нет тарифа»), имя и логотип вендора,
 * парковка, сумма, долг, метка «в документе иначе», вид (с парковкой словом или без, строка выданной — `out` с её деньгами)
 * и админ ли смотрит (значок связи). Ключ у каждой строки свой: правка одной ТС
 * перерисовывает только её. Все строки — одним чтением кэша.
 */
final class TableRows
{
    /** @param  Collection<int, Vehicle>  $vehicles @return array<int, string> id ТС => HTML строки */
    public static function render(Collection $vehicles, array $totals, array $debts, bool $place, bool $out = false, bool $money = true): array
    {
        if ($vehicles->isEmpty()) {
            return [];
        }
        // Значок связи (x-ui.links) — только админу: строка админа и управляющего — разные строки кэша.
        // Строка приёмщика (без денег, `User::parkMoney`) — своя строка кэша.
        $common = Accrual::mark('park_tariffs').'|'.self::templates().'|'.(int) (auth()->user()?->isAdmin() ?? false).'|'.(int) $money;
        $docs = FillFromDocs::flags($vehicles);
        $keys = $vehicles->mapWithKeys(fn (Vehicle $v) => [$v->id => 'park.row:'.md5(implode('|', [
            // Машина (`Cars\Car`: марка, VIN, номер — их правят и из CRM) и её продажа со сделкой (значок связи) — свои
            // записи: их правка ТС не трогает.
            $common, $v->id, $v->updated_at?->getTimestamp(), $v->carRecord()->updated_at?->getTimestamp(),
            $v->offer ? $v->offer->id.':'.$v->offer->updated_at?->getTimestamp().':'.$v->offer->deal?->id : '',
            $v->threads_count, $v->waiting_count ?? 0,
            $v->requests->map(fn ($r) => $r->id.':'.$r->updated_at?->getTimestamp())->implode(','), today()->toDateString(),
            $v->vendor?->name, $v->vendor?->logoUrl(), $v->yard?->name,
            json_encode([$totals[$v->id] ?? null, $debts[$v->id] ?? 0, $place, $docs[$v->id], $out, $v->getAttribute('money_state'), $v->getAttribute('task_keys')]),
        ]))])->all();
        $cached = Cache::many(array_values($keys));
        $rows = $fresh = [];
        foreach ($vehicles as $v) {
            $html = $cached[$keys[$v->id]] ?? null;
            if ($html === null) {
                $html = view('components.park.table-row', ['vehicle' => $v, 'total' => $totals[$v->id] ?? null, 'debt' => $debts[$v->id] ?? 0, 'place' => $place, 'out' => $out, 'money' => $money])->render();
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
        // Содержимое, а не дата файла: в образе у всех файлов одна дата, и правка шаблона кэш не сбрасывала (12.10.2026,
        // госномер и значок связи стояли в старом порядке). Процесс живёт до выкладки — хэш считается раз.
        static $hash;

        return $hash ??= md5(implode(',', array_map(fn ($path) => @md5_file($path) ?: '', [app_path('Park/Status.php'), ...array_map(fn ($f) => resource_path("views/components/{$f}.blade.php"),
            ['park/table-row', 'park/alerts', 'park/ref', 'ui/plate', 'ui/copy-code', 'ui/icon', 'ui/links', 'vendor/logo', 'vendor/name'])])));
    }
}
