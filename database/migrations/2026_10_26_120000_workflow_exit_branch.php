<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ветка исхода маршрута (03.10.2026, гараж через подтверждение): garage — только гаражной сделке, где поставщику платим
 * мы; buyer — всем остальным; пусто — всем. Дата — до `carcade_stock_route`: та на чистой базе применяет заготовку,
 * а заготовки пишут ветку.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_exits', 'branch')) {
            Schema::table('workflow_exits', fn (Blueprint $t) => $t->string('branch', 8)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('workflow_exits', fn (Blueprint $t) => $t->dropColumn('branch'));
    }
};
