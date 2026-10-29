<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Память VIN — по машинам (29.10.2026, «каждая вещь — одна сущность»): `vin_facts` держала копию полей машины по
// дорожкам (предложение, ТС парковки, лот закупки) — 1620 строк на 1429 VIN, шесть расходились с машиной, восемь
// пережили смену VIN. Все поля уже лежат в `cars`; `VinMemory` спрашивает её по началу VIN.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists cars_vin_prefix_index on cars (substr(vin, 1, 11) text_pattern_ops) where brand_id is not null');
        Schema::dropIfExists('vin_facts');
    }

    public function down(): void
    {
        DB::statement('drop index if exists cars_vin_prefix_index');
        Schema::create('vin_facts', function ($table) {
            $table->id();
            $table->string('vin', 17);
            $table->string('prefix', 11)->index();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('car_models')->nullOnDelete();
            $table->smallInteger('year')->nullable();
            $table->string('transmission', 16)->nullable();
            $table->string('drive', 8)->nullable();
            $table->string('fuel', 16)->nullable();
            $table->string('body', 24)->nullable();
            $table->smallInteger('engine_volume')->nullable();
            $table->smallInteger('engine_power')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });
    }
};
