<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Я передумал» → «Отказываюсь» (19.11.2026, начальник): отступной исход менеджера (`Outcome::isRetreat` — назад в приём
 * подтверждений) во всех маршрутах. Журнал и ответы просьб остаются прежними словами — это история; в чате прежний ответ
 * показывается новой подписью (`StepAsk::answered`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_exits')->where('actor', 'manager')->where('label', 'Я передумал')
            ->whereIn('to_stage_id', DB::table('workflow_stages')->where('offer_state', 'open')->select('id'))
            ->update(['label' => 'Отказываюсь']);
    }

    public function down(): void {}
};
