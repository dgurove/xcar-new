<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Память декодера VIN: что человек записал рядом с этим номером. Бесплатных
// таблиц по российскому парку нет — единственный источник, который растёт сам.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vin_facts', function (Blueprint $table) {
            $table->id();
            $table->string('vin', 17);
            $table->string('prefix', 11)->index();   // VIN без серийника: WMI, VDS, год, завод
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

    public function down(): void
    {
        Schema::dropIfExists('vin_facts');
    }
};
