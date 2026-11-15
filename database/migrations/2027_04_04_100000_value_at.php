<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дата оценочной (15.11.2026, владелец: «18 октября Альфа прислала 6 млн, 26 октября — заявку на приём, в ней 3 млн; это более
 * свежая оценка»): `value_at` — на какой день оценочная верна, по источнику, а не по тому, когда её внесли. Свежее побеждает,
 * откуда бы ни пришло (`Offers\Valuations`). У продажи — `offers.value_at`, у ТС парковки — `cars.value_at`.
 * Прошлое заполняет `offers:valuations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->timestamp('value_at')->nullable());
        Schema::table('cars', fn (Blueprint $t) => $t->timestamp('value_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('value_at'));
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('value_at'));
    }
};
