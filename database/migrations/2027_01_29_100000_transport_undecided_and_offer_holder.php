<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21.10.2026, владелец. `transports.undecided` — «Кто вывозит: не определено» при старте сделки: откуда и к кому везти
 * известно, кто повезёт — нет; задача заведена тихо, метку снимает любое назначение. `offers.held_by` — машина стоит у
 * менеджера, хотя его сделки уже нет: Иван взял её в гараж, привёз, чинить не стал — её продают другому, а забирать — у
 * него («Вернуть в продажу», `ReturnToSale`); гасит её уезд машины (`Deliver`, `SelfPickup`, выдача с парковки).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->boolean('undecided')->default(false);
        });
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('held_by');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('held_by');
        });
        Schema::table('transports', function (Blueprint $t) {
            $t->dropColumn('undecided');
        });
    }
};
