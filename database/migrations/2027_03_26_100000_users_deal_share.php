<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доля менеджера от разницы в сделке, % (13.11.2026, владелец: «у Сахиба по умолчанию 50:50 от разницы закупочной и
 * продажной, с возможностью поменять»): вознаграждение по умолчанию при принятии (`Deal::defaultCommission`). Пусто —
 * не подставляется, как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->unsignedTinyInteger('deal_share')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deal_share'));
    }
};
