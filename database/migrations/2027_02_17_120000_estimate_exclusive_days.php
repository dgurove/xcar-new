<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Эксклюзив — выбор админа в шторке «Отдать на оценку», а не автоматика (02.11.2026, Андрей: «не отправлять Роме
 * автоматически»): сколько дней после оценки машина в продаже только оценившему (`FirstLook`), null — без эксклюзива.
 * Просьбы, что ставила система (`auto`), — пять дней, как было.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->unsignedSmallInteger('exclusive_days')->nullable());
        DB::table('offer_estimates')->where('auto', true)->update(['exclusive_days' => 5]);
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('auto'));
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->boolean('auto')->default(false));
        DB::table('offer_estimates')->whereNotNull('exclusive_days')->update(['auto' => true]);
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('exclusive_days'));
    }
};
