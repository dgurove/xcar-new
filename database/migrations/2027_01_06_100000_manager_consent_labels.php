<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Согласие менеджера (10.10.2026, владелец): «Покупаю» → «Беру в работу», «Отказываюсь» → «Я передумал» — во всех
 * маршрутах (Т-Страхование, Альфа, Каркаде). Журнал и ответы просьб остаются прежними словами — это история.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_exits')->where('actor', 'manager')->where('label', 'Покупаю')->update(['label' => 'Беру в работу']);
        DB::table('workflow_exits')->where('actor', 'manager')->where('label', 'Отказываюсь')->update(['label' => 'Я передумал']);
        DB::table('workflow_stages')->where('ask_title', 'Подтвердите покупку')->update(['ask_title' => 'Возьмите сделку в работу']);
        DB::table('workflow_stages')->where('ask_text', 'like', '%Подтвердите покупку или откажитесь от неё%')
            ->update(['ask_text' => DB::raw("replace(ask_text, 'Подтвердите покупку или откажитесь от неё', 'Возьмите сделку в работу или откажитесь от неё')")]);
        // Открытые просьбы этого шага — тем же словом.
        DB::table('requirements')->whereNull('done_at')->where('title', 'Подтвердите покупку')->update(['title' => 'Возьмите сделку в работу']);
    }

    public function down(): void {}
};
