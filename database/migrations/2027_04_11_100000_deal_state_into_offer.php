<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Сделка — состояние предложения (16.11.2026, начальник: «сделка и предложение — состояния одной сущности»; владелец
 * выбрал «одна сущность, условия при ней»). Своего состояния у сделки больше нет — факты:
 * - `deals.closed_at` — условия закрыты (продали по ним или они сорвались);
 * - `deals.cancelled_at` — сорвались;
 * - `deals.parallel` — поручена параллельно;
 * - `offers.deal_id` — текущие условия машины (идущие или те, по которым её продали; прежний `lastDeal`).
 * Было → стало: active — не закрыта и не параллельная; done — закрыта, не сорвалась; cancelled — сорвалась; parallel —
 * параллельная и не закрыта. Данные не правятся молча: закрытие, что не сходится с состоянием, останавливает миграцию.
 * Колонка `deals.state` остаётся на одну выкладку (старые контейнеры читают её, пока идёт выкладка; модель пишет туда
 * выведенное состояние) — её снимает следующая выкладка, сверку окна делает `deals:reconcile`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::selectOne("select count(*) n from deals where (state in ('done', 'cancelled') and closed_at is null)
            or (state in ('active', 'parallel') and closed_at is not null)")->n;
        if ($bad > 0) {
            throw new RuntimeException("Сделок, у которых закрытие не сходится с состоянием: {$bad} — разобрать руками");
        }
        $twice = DB::selectOne("select count(*) n from (select offer_id from deals where state = 'active' group by 1 having count(*) > 1) x")->n;
        if ($twice > 0) {
            throw new RuntimeException("Машин с двумя идущими сделками: {$twice}");
        }

        DB::statement("update deals set cancelled_at = closed_at where state = 'cancelled' and cancelled_at is null");
        DB::statement("update deals set parallel = true where state = 'parallel'");
        // Текущие условия — последняя идущая или состоявшаяся (как прежний `Offer::lastDeal`).
        DB::statement("update offers o set deal_id = (select max(d.id) from deals d where d.offer_id = o.id and d.state in ('active', 'done'))");

        DB::statement('drop index if exists deals_one_active');
        DB::statement('drop index if exists deals_one_live_per_buyer');
        DB::statement('create unique index deals_one_active on deals (offer_id) where closed_at is null and not parallel');
        DB::statement('create unique index deals_one_live_per_buyer on deals (offer_id, buyer_id) where closed_at is null and buyer_id is not null');
    }

    public function down(): void
    {
        DB::statement("update deals set state = case when cancelled_at is not null then 'cancelled' when closed_at is not null then 'done'
            when parallel then 'parallel' else 'active' end");
        DB::statement('drop index if exists deals_one_active');
        DB::statement('drop index if exists deals_one_live_per_buyer');
        DB::statement("create unique index deals_one_active on deals (offer_id) where state = 'active'");
        DB::statement("create unique index deals_one_live_per_buyer on deals (offer_id, buyer_id) where state in ('active', 'parallel') and buyer_id is not null");
    }
};
