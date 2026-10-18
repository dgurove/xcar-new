<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Вывоз внутри региона или между регионами (`Logistics\Reach`, 18.10.2026): у старых задач — не указано. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->string('reach', 12)->nullable()->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->dropColumn('reach'));
    }
};
