<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пришло заявкой на приём с парковки (вендор «Сразу в продажу», `Park\Actions\AutoSale`, 26.11.2026, владелец: «они всегда
 * попадают сразу в „Без продажной цены“, в „Без закупочной“ их никто не заметит, это потерянные деньги»): черновик стоит в
 * «Без продажной цены» и без закупочной, не распознанное — «не распознано». Снимает правило Мигторга и «Оценочная неактуальна».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('park_intake_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('park_intake_at');
        });
    }
};
