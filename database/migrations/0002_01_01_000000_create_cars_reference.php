<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->string('name_ru', 120)->nullable();
            $table->string('country', 64)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->timestamps();
        });

        Schema::create('car_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 96);
            $table->string('name', 160);
            $table->string('name_ru', 160)->nullable();
            $table->string('class', 4)->nullable();
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'slug']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('type', 16)->nullable();
            $table->string('region_code', 4)->nullable()->index();
            $table->boolean('is_federal_city')->default(false);
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('car_models');
        Schema::dropIfExists('brands');
    }
};
