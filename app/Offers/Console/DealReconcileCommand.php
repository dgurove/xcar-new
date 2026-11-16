<?php

namespace App\Offers\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сверка после выкладки «сделка — состояние предложения» (16.11.2026): пока шла выкладка, старые контейнеры писали
 * прежнюю колонку `deals.state` (отменили — `cancelled` и `closed_at`, переоткрыли — `active`), а новый код её только
 * зеркалит. Где колонка не сходится с фактами — это запись старого кода: факты берутся из неё. Ещё — указатель машины
 * (`offers.deal_id`) у идущих, кому его не поставили. Безопасно гонять сколько угодно; без колонки — только указатели.
 * Без `--apply` — только числа.
 */
class DealReconcileCommand extends Command
{
    protected $signature = 'deals:reconcile {--apply}';

    protected $description = 'Сверить факты сделок с прежней колонкой state и указатели машин';

    public function handle(): int
    {
        $fixes = Schema::hasColumn('deals', 'state') ? [
            'отменены старым кодом' => "update deals set cancelled_at = coalesce(closed_at, updated_at), closed_at = coalesce(closed_at, updated_at)
                where state = 'cancelled' and cancelled_at is null",
            'закрыты старым кодом' => "update deals set closed_at = updated_at where state = 'done' and closed_at is null",
            'снова идут' => "update deals set closed_at = null, cancelled_at = null, parallel = false
                where state = 'active' and (closed_at is not null or cancelled_at is not null or parallel)",
            'стали параллельными' => "update deals set closed_at = null, cancelled_at = null, parallel = true
                where state = 'parallel' and (closed_at is not null or cancelled_at is not null or not parallel)",
        ] : [];
        $fixes += [
            'указатель на сорванную снят' => 'update offers o set deal_id = null from deals d where d.id = o.deal_id and d.cancelled_at is not null',
            'указатель на идущую' => 'update offers o set deal_id = d.id from deals d where d.offer_id = o.id and d.closed_at is null and not d.parallel
                and o.deal_id is distinct from d.id',
        ];
        $apply = (bool) $this->option('apply');
        DB::transaction(function () use ($fixes, $apply) {
            foreach ($fixes as $what => $sql) {
                $n = $apply ? DB::affectingStatement($sql) : $this->count($sql);
                $this->line(($apply ? 'исправлено' : 'к исправлению').": {$what} — {$n}");
            }
        });

        return self::SUCCESS;
    }

    /** Сколько строк задел бы `update` — тем же условием в `select` внутри отменённой транзакции. */
    private function count(string $sql): int
    {
        DB::beginTransaction();
        try {
            return DB::affectingStatement($sql);
        } finally {
            DB::rollBack();
        }
    }
}
