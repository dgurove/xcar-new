<?php

namespace App\Workflow;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Сколько этап занимает на деле (экран «Таймеры», 19.01.2027: начальник ставит срок по факту, а не наугад) — медиана «вход
 * в этап → следующий вход» по журналу продажи за 90 дней. Журнал хранит имя этапа, не id, — ключ «вендор предложения + имя».
 * Час в кэше: тяжёлый проход по журналу.
 */
final class StageStats
{
    /** @return array<int, array<string, array{median: int, count: int}>> вендор → имя этапа → медиана в минутах и число входов */
    public static function medians(): array
    {
        return Cache::remember('workflow:stage-medians', now()->addHour(), function () {
            $rows = DB::select(<<<'SQL'
                with e as (
                    select ev.offer_id, ev.created_at, ev.payload->>'to' as stage,
                           lead(ev.created_at) over (partition by ev.offer_id order by ev.id) as next_at
                    from car_events ev
                    where ev.type = 'stage_entered' and ev.payload->>'track' = 'sale' and ev.created_at > now() - interval '90 days'
                )
                select o.vendor_id, e.stage, count(*) as n,
                       percentile_cont(0.5) within group (order by extract(epoch from e.next_at - e.created_at) / 60) as median
                from e join offers o on o.id = e.offer_id
                where e.next_at is not null and o.vendor_id is not null
                group by o.vendor_id, e.stage
                SQL);
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r->vendor_id][(string) $r->stage] = ['median' => (int) round((float) $r->median), 'count' => (int) $r->n];
            }

            return $out;
        });
    }

    /** «обычно 6 д» — от трёх переходов, иначе случайность. */
    public static function usual(?array $row): ?string
    {
        if (! $row || $row['count'] < 3) {
            return null;
        }
        $m = $row['median'];

        return 'обычно '.($m >= 1440 ? round($m / 1440, $m < 4320 ? 1 : 0).' д' : ($m >= 60 ? round($m / 60).' ч' : max(1, $m).' мин'));
    }
}
