<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Где машина физически — свойство машины (29.10.2026, владелец: одна машина во всех разделах): `offers.car_place` →
 * `cars.place` (у продажи по-прежнему `car_place`, `Offer::carAliases`). Место знали только машины с продажей и только с
 * тех пор, как его ставили; машина, что стоит на нашей парковке, а места нет или оно «у владельца» (приняли после того, как
 * его ставили), — «На нашей парковке».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $t) {
            $t->string('place', 16)->nullable();
        });
        DB::statement('update cars c set place = o.car_place from offers o where o.car_id = c.id and o.car_place is not null');
        DB::statement("update cars c set place = 'ours' from park_vehicles v where v.car_id = c.id and v.state = 'stored' and (c.place is null or c.place = 'owner')");
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn('car_place');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->string('car_place', 16)->nullable();
        });
        DB::statement('update offers o set car_place = c.place from cars c where c.id = o.car_id');
        Schema::table('cars', function (Blueprint $t) {
            $t->dropColumn('place');
        });
    }
};
