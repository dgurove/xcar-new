<?php

namespace App\Users;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Отклик менеджера за две недели (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины»; на проде
 * Макаров открыл 221 из 300 и подтвердил 41, Бородин открыл 2): сколько предложений ему открылось (`offer_viewers.opens_at`
 * в периоде), сколько он открыл (`offer_reads` или своё подтверждение), сколько подтвердил сам (`placed_by` пуст —
 * не «за менеджера») и через сколько обычно открывает (медиана). Видно админу — в карточке человека и в списке менеджеров
 * CRM, сводок в Telegram нет. Одним запросом на всех, кэш 10 минут — массивом, не объектом.
 */
final class ManagerResponse
{
    public const DAYS = 14;

    /** Открыл меньше — красным: менеджеру пора звонить. */
    public const LOW = 0.2;

    public function __construct(public int $seen, public int $opened, public int $bids, public ?int $medianSeconds) {}

    /** Доля открытых, null — ему ничего не открывалось. */
    public function share(): ?float
    {
        return $this->seen ? $this->opened / $this->seen : null;
    }

    public function low(): bool
    {
        return $this->seen > 0 && $this->share() < self::LOW;
    }

    /** «74 %», ни одного — «ни одного». */
    public function shareLabel(): ?string
    {
        return match (true) {
            ! $this->seen => null,
            ! $this->opened => 'ни одного',
            // Одно из трёхсот — не «0 %».
            default => max(1, (int) round($this->share() * 100)).' %',
        };
    }

    public static function of(User $user): ?self
    {
        $row = self::rows()[$user->id] ?? null;

        return $row ? new self(...$row) : null;
    }

    /** @return array<int, self> по id человека */
    public static function all(): array
    {
        return array_map(fn (array $row) => new self(...$row), self::rows());
    }

    /** Доля открытых по человеку — подзапросом для сортировки списка: `(select share from (…) r where r.user_id = users.id)`. */
    public static function shareSql(): string
    {
        return '(select r.opened::float / nullif(r.seen, 0) from ('.self::SQL.') r where r.user_id = users.id)';
    }

    // Открыл — смотрел на сайте хоть раз или подтвердил (подтвердить, не открыв, можно из бота предложений); медиана —
    // только по просмотрам после того, как машина ему открылась.
    private const SQL = "select v.user_id, count(*) as seen, count(*) filter (where r.read_at is not null or b.offer_id is not null) as opened,
            count(b.offer_id) as bids,
            percentile_cont(0.5) within group (order by extract(epoch from r.read_at - greatest(v.opens_at, o.published_at)))
                filter (where r.read_at >= greatest(v.opens_at, o.published_at)) as median_s
        from offer_viewers v
        join offers o on o.id = v.offer_id and o.published_at is not null and o.is_demo = false
        left join offer_reads r on r.user_id = v.user_id and r.offer_id = v.offer_id
        left join lateral (select bb.offer_id from bids bb where bb.offer_id = v.offer_id and bb.user_id = v.user_id and bb.placed_by is null limit 1) b on true
        where v.opens_at > now() - interval '".self::DAYS." days' and v.opens_at <= now()
        group by v.user_id";

    /** @return array<int, array{seen: int, opened: int, bids: int, medianSeconds: ?int}> */
    private static function rows(): array
    {
        return once(fn () => Cache::remember('managers.response', 600, fn () => collect(DB::select(self::SQL))
            ->mapWithKeys(fn ($r) => [(int) $r->user_id => ['seen' => (int) $r->seen, 'opened' => (int) $r->opened, 'bids' => (int) $r->bids,
                'medianSeconds' => $r->median_s === null ? null : (int) round((float) $r->median_s)]])->all()));
    }
}
