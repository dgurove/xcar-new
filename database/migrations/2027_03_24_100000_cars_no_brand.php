<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Марки нет» (12.11.2026, начальник: прицепы и кузова Альфы без шильдика не уходили в продажу — «нет марки»): признак машины,
 * что марки у неё нет вовсе, а не что её забыли вписать. Публикация его принимает (`ChangeOfferState::missing`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->boolean('no_brand')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('no_brand');
        });
    }
};
