<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * У продажи своя оценочная (15.11.2026, владелец: «если появились на Мигторге, оценочная с заявки становится
 * неактуальной»). Была одна `cars.value` на парковку и продажу: по ней парковка считает ставку хранения (`from_value`),
 * а продажа — закупочную. Оценочная с парковки продаже годится, пока машины нет на Мигторге; `value_from = park` —
 * пришла с парковки (`Offers\ParkValue`). Копия — во все предложения; «с парковки» — где у машины есть ТС парковки, а в
 * CRM оценочную ни разу не меняли (из текста, рукой, переоценкой).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->unsignedBigInteger('value')->nullable();
            $t->string('value_from', 8)->nullable();
        });
        DB::statement('update offers o set value = c.value from cars c where c.id = o.car_id and c.value is not null');
        DB::statement("update offers o set value_from = 'park' where o.value is not null
            and exists (select 1 from park_vehicles v where v.car_id = o.car_id)
            and not exists (select 1 from car_events e where e.offer_id = o.id and jsonb_exists(e.payload->'changes', 'value'))");
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['value', 'value_from']));
    }
};
