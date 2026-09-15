<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявленная цена: то, что менеджер видит как «закупочную». Пусто — равна
 * закупочной; задана — менеджер видит её, а настоящая закупочная остаётся у нас.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedInteger('publish_price')->nullable()->after('floor_price');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('publish_price');
        });
    }
};
