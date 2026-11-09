<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Эксклюзив за оценку дан — до когда (`App\Offers\Exclusive`, 09.11.2026). Уже выходившим в продажу после оценки он считается
 * данным: заново его не ставить, когда машина вернётся в продажу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', function (Blueprint $t) {
            $t->timestamp('exclusive_until')->nullable();
        });
        DB::statement(<<<'SQL'
            update offer_estimates e set exclusive_until = case when o.published_at >= e.estimated_at then o.published_at + make_interval(days => e.exclusive_days) else now() end
            from offers o
            where o.id = e.offer_id and e.exclusive_days > 0 and e.estimated_at is not null
              and (o.state <> 'draft' or o.published_at >= e.estimated_at)
        SQL);
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('exclusive_until'));
    }
};
