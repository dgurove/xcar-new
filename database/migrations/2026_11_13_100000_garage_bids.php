<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Гараж через подтверждение (03.10.2026): менеджер подтверждает «для покупателя» с ценой или «в гараж» без неё,
 * принятое гаражное ведёт сделку по маршруту вендора, машина проходит этапы гаража.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $t) {
            $t->string('kind', 8)->default('buyer');
            $t->unsignedInteger('amount')->nullable()->change();
        });

        Schema::table('offers', function (Blueprint $t) {
            $t->boolean('garage_allowed')->default(true);
        });

        // Кто платит поставщику по гаражной сделке: us | manager; у обычной — пусто.
        Schema::table('deals', function (Blueprint $t) {
            $t->string('garage_payer', 8)->nullable();
        });

        Schema::table('garage_cars', function (Blueprint $t) {
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->timestamp('stage_at')->nullable();
            // Путь машины по этапам: [[этап, когда], …] — даты пройденных шагов в карточке.
            $t->jsonb('history')->nullable();
            $t->string('invoice_to', 8)->nullable();
            $t->foreignId('payout_invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
        });

        // supplier — строка «Оплата поставщику», которую гараж пишет сам: менеджер её не правит.
        Schema::table('garage_costs', function (Blueprint $t) {
            $t->string('kind', 12)->nullable();
        });

        DB::statement("UPDATE garage_cars SET stage_at = CASE state WHEN 'sold' THEN coalesce(sold_at, taken_at) WHEN 'settled' THEN coalesce(settled_at, sold_at, taken_at) ELSE taken_at END");
        // Прежние машины начинали с ремонта (теперь «Подготовка»): путь — от взятия, продажи и расчёта.
        DB::statement(<<<'SQL'
            UPDATE garage_cars SET history = (
                SELECT jsonb_agg(jsonb_build_array(s, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY n)
                FROM (VALUES (1, 'repair', taken_at), (2, 'sold', sold_at), (3, 'settled', settled_at)) v(n, s, at) WHERE at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('garage_costs', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('garage_cars', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payout_invoice_id');
            $t->dropConstrainedForeignId('deal_id');
            $t->dropColumn(['stage_at', 'history', 'invoice_to']);
        });
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('garage_payer'));
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('garage_allowed'));
        Schema::table('bids', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
