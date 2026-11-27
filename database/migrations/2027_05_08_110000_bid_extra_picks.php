<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. запрос прямо в подтверждении (27.11.2026, Корабельников: «подтверждаю за N или в гараж и сразу запрашиваю осмотр и ПТС»;
 * владелец выбрал П2 — та же шторка): что отметил — ид шаблонов `request`; приняли подтверждение — сделка открывается с его
 * просьбой (`AcceptBid` → `WantExtra`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', fn (Blueprint $t) => $t->jsonb('extra_picks')->nullable());
    }

    public function down(): void
    {
        Schema::table('bids', fn (Blueprint $t) => $t->dropColumn('extra_picks'));
    }
};
