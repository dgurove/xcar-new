<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Регион задачи — чип «Регион» и числа пилюль логистики ищут по нему, а внешний ключ Postgres индексом не снабжает
 * (`foreign_key_indexes`, 12.10.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->index('region_id'));
    }

    public function down(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->dropIndex(['region_id']));
    }
};
