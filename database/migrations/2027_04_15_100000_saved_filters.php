<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сохранённые фильтры человека (17.11.2026, владелец: «продвинутый фильтр с возможностью сохранения параметров с вводом
 * названия»; «только свои»): `list` — общий список колеса «Предложений» (`offers`), `query` — вкладка, сортировка и все чипы
 * её списка (пустые — явно, чтобы память `ListPrefs` не подмешалась), `position` — порядок в шторке «Фильтр».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('list', 32);
            $table->string('name', 60);
            $table->jsonb('query');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'list', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
