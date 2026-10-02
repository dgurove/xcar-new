<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Ставка от оценочной стоимости» у вендора (02.10.2026, владелец: «зачем СОГАЗу, ВСК, Совкому поле стоимости — она
 * имеет значение только для Альфы»). Включено — в деле ТС есть поле «Оценочная стоимость», «✨» её предлагает;
 * выключено — поля нет. Сразу включено у тех, чей прайс уже со ступенями по стоимости, и у АльфаСтрахования
 * (у Москвы прайс по стоимости ещё не заведён, а стоимость в делах уже вписана).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('rate_by_value')->default(false);
        });
        DB::table('vendors')
            ->whereIn('id', DB::table('park_tariffs')->whereNotNull('from_value')->whereNotNull('vendor_id')->select('vendor_id'))
            ->orWhere('name', 'ilike', 'альфа%')
            ->update(['rate_by_value' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('rate_by_value'));
    }
};
