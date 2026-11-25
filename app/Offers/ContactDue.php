<?php

namespace App\Offers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Менеджер забирает ТС сам, а телефона владельца не видел — ход наш (25.11.2026, владелец по BMW X5 915891/2026: «менеджер тупо не
 * видит контакты, не понимает, откуда вывозить, кому звонить»; «если наступил этап вывоза, а у менеджера нет контактов, — это наш
 * ход, а не „Ждём менеджера“»). Одна дверь: шаг сделки (`Position::contactDue`, `NEEDS_US_SQL`), строка шага (`x-route.who`,
 * `x-deal.contact-shown`) и вкладка «Показать контакты» раздела «Вывоз» (`Admin\PickupController`).
 *
 * Ждёт, когда: сделка идёт, её менеджер — исполнитель последнего вывоза продажи машины (`channel = manager`), машину ещё не
 * забрали, шаг продажи — получение или связь перед ним (`Stage::handsStep`), и по сделке не показан ни один телефон
 * (`deals.manager_sees.contact`, `ManagerView`). Контакта в карточке может не быть вовсе — тогда его ищут в письме или вписывают.
 */
final class ContactDue
{
    /** Шаг продажи `p` — получение («Автомобиль забрал» менеджера) или шаг связи перед ним. */
    private const HANDS_SQL = "exists (select 1 from workflow_exits x where x.stage_id = p.stage_id and x.actor = 'manager'
        and (lower(x.label) = 'автомобиль забрал' or exists (select 1 from workflow_exits y where y.stage_id = x.to_stage_id
            and y.actor = 'manager' and lower(y.label) = 'автомобиль забрал')))";

    /** Сделка `d` (идёт) с вывозом `pt` у своего менеджера, телефон не показан. */
    private const CORE_SQL = "d.buyer_id is not null and ".Deal::ACTIVE_D." and d.garage_payer is distinct from 'us'
        and pt.channel = 'manager' and pt.executor_id = d.buyer_id and pt.picked_at is null
        and (d.manager_sees->>'contact') is null";

    /** Над `offer_positions`: позиция продажи, чьей сделке нужно показать контакт (`Position::NEEDS_US_SQL`). */
    public const DUE_SQL = "exists (select 1 from offers co join deals d on d.offer_id = co.id
        join transports pt on pt.car_id = co.car_id and pt.id = (".\App\Logistics\PickupLetters::LATEST_SQL.")
        join offer_positions p on p.id = offer_positions.id
        where co.id = offer_positions.offer_id and ".self::CORE_SQL." and ".self::HANDS_SQL.')';

    /** Строки «машина ждёт показа контакта»: offer_id, deal_id, transport_id, с какого момента. */
    private static function rows(): Collection
    {
        $load = fn () => collect(DB::select("select co.id as offer_id, d.id as deal_id, pt.id as transport_id,
                coalesce(p.block_entered_at, p.entered_at) as since
            from offers co join deals d on d.offer_id = co.id
            join transports pt on pt.car_id = co.car_id and pt.id = (".\App\Logistics\PickupLetters::LATEST_SQL.")
            join offer_positions p on p.offer_id = co.id and p.track = 'sale'
            where co.is_demo is not true and ".self::CORE_SQL.' and '.self::HANDS_SQL));

        return app()->runningInConsole() ? $load() : once($load);
    }

    /** offer_id → с какого момента ждёт (вошли в шаг). @return Collection<int, Carbon> */
    public static function waiting(): Collection
    {
        return self::rows()->mapWithKeys(fn ($r) => [(int) $r->offer_id => Carbon::parse($r->since ?? now())]);
    }

    /** Вывозы вкладки «Показать контакты». @return list<int> */
    public static function transportIds(): array
    {
        return self::rows()->pluck('transport_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Ждёт ли эта сделка показа контакта. */
    public static function due(Deal $deal): bool
    {
        return self::rows()->contains(fn ($r) => (int) $r->deal_id === $deal->id);
    }

    /** Когда менеджеру показали телефон (последний показ), null — не показывали. */
    public static function shownAt(Deal $deal): ?Carbon
    {
        return ManagerView::since($deal, ManagerView::CONTACT);
    }
}
