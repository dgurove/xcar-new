<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Значения полей машины по источникам (07.11.2026, владелец: «в одном месте подставляет из документов, в другом из
 * Мигторга, они спорят»; `Cars\Sources`): что о поле говорит лот Мигторга, письмо, каждый документ — строкой, в готовом к
 * записи виде. Расхождение — выбор человека по полю. `cars.field_sources` — кто последним вписал поле (человек, Мигторг,
 * письмо, документ): стёртое человеком источник не заполняет снова. `cars.value_conflicts` — сколько полей спорные
 * (метка «в документе иначе» в строках без разбора).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('car_id')->constrained('cars')->cascadeOnDelete();
            $t->string('field', 24);
            $t->string('source', 12);
            $t->string('source_id', 40);
            $t->string('title', 120);
            $t->jsonb('write');
            $t->string('key', 160);
            $t->string('label', 200);
            $t->timestamp('created_at')->nullable();
            $t->unique(['car_id', 'field', 'source', 'source_id']);
        });
        Schema::table('cars', function (Blueprint $t) {
            $t->jsonb('field_sources')->nullable();
            $t->unsignedSmallInteger('value_conflicts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_values');
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn(['field_sources', 'value_conflicts']));
    }
};
