<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отказ менеджера от сделки (19.11.2026, начальник): `refused_at` — когда менеджер отказался (вкладка «Менеджер отказался», пока
 * есть другие подтверждения); `lapse_since` — авто-снятие: приём кончился и после этого момента никто не подтвердил — в архив и
 * письмо поставщику «не актуально» (`Offers\Actions\LapseRefused`). Пусто — авто-снятия нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('refused_at')->nullable();
            $t->timestamp('lapse_since')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn(['refused_at', 'lapse_since']);
        });
    }
};
