<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт и ссылка — только после согласования с поставщиком (09.10.2026, владелец: Каркаде ещё не подтвердил, а менеджеру
 * уже «Оплатите»): `deals.agreed_at` ставит `AgreeDeal`. Прежним сделкам — дата принятия, кроме тех, что сейчас стоят
 * на согласовании (`Stage::isAgreement`): их счета гасит `deals:agreement-fix`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('agreed_at')->nullable()->after('state'));

        DB::statement(<<<'SQL'
            update deals d set agreed_at = d.created_at
            where not (d.state = 'active' and exists (
                select 1 from offer_positions p join workflow_exits e on e.stage_id = p.stage_id
                where p.offer_id = d.offer_id and p.track = 'sale'
                  and (e.label ilike 'поставщик согласовал%' or (e.actor = 'manager' and e.label ilike 'покупаю'))))
            SQL);
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('agreed_at'));
    }
};
