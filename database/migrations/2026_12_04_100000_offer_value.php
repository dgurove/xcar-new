<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оценочная стоимость у предложения (владелец, 05.10.2026): у Альфы закупочная считается от неё (`Sale::floorFrom`),
 * а оценочные приходят модераторам сообщением, когда черновик давно заведён. Поле то же, что у ТС парковки, — машина
 * одна: связанные предложение и ТС держат его вместе (`Sale::MAP`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->unsignedInteger('value')->nullable());
        DB::statement('update offers o set value = v.value from park_vehicles v where v.offer_id = o.id and v.value is not null');
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('value'));
    }
};
