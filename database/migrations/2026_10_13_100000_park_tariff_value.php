<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ступень прайса по заявленной стоимости: у АльфаСтрахования сутки легкового зависят от стоимости
 * (250 ₽ до 500 000, дальше 300, 350, 400, 450), а у остального транспорта — от типа. `from_value`
 * пуст — строка на любую стоимость, как было; ступень действует от этой суммы и выше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_tariffs', function (Blueprint $t) {
            $t->unsignedBigInteger('from_value')->nullable()->after('from_day');
        });
        DB::statement('drop index park_tariffs_cell');
        DB::statement('create unique index park_tariffs_cell on park_tariffs (vendor_id, yard_id, category, service, from_day, from_value, valid_from) nulls not distinct');
    }

    public function down(): void
    {
        DB::statement('drop index park_tariffs_cell');
        DB::statement('create unique index park_tariffs_cell on park_tariffs (vendor_id, yard_id, category, service, from_day, valid_from) nulls not distinct');
        Schema::table('park_tariffs', fn (Blueprint $t) => $t->dropColumn('from_value'));
    }
};
