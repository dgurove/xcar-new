<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Каркаде: забирать — только после согласия поставщика (11.10.2026). До правки по гаражным сделкам Каркаде менеджерам
 * ушло «Вам поручен вывоз» (Passat CC у Макарова) — строки ленты по машинам, где поставщик ещё не согласовал, стираются;
 * «Пора забирать» придёт, когда отметят «Поставщик согласовал».
 */
return new class extends Migration
{
    public function up(): void
    {
        $numbers = DB::table('deals as d')
            ->join('offers as o', 'o.id', '=', 'd.offer_id')
            ->join('vendors as v', 'v.id', '=', 'o.vendor_id')
            ->where('d.state', 'active')->whereNull('d.agreed_at')->where('v.deal_format', 'wholesale')
            ->pluck('o.number')->filter()->map(fn ($n) => (string) $n)->all();
        if (! $numbers) {
            return;
        }
        DB::table('notifications')->where('type', 'App\\Notifications\\PickupNotice')
            ->whereIn(DB::raw("data->>'offer'"), $numbers)->delete();
    }

    public function down(): void {}
};
