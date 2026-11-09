<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Эксклюзив за оценку снят админом осознанно — крестиком «Снять с оценки» или «Выставить всем…» (`App\Offers\Exclusive`,
 * 09.11.2026). Прежние просьбы, снятые до оценки у черновика без цены продажи, — снятые: цену не ставили, значит, крестик.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', function (Blueprint $t) {
            $t->timestamp('withdrawn_at')->nullable();
        });
        DB::statement(<<<'SQL'
            update offer_estimates e set withdrawn_at = e.updated_at
            from offers o
            where o.id = e.offer_id and e.state = 'closed' and e.estimated_at is null and o.asking_price is null
        SQL);
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('withdrawn_at'));
    }
};
