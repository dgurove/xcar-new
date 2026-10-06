<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Отклонённое у «!» Мигторга (владелец 06.10.2026): поле → текст Мигторга, который больше не показываем. */
return new class extends Migration
{
    public function up(): void
    {
        // Колонку могла поставить выкладка соседней сессии, собранная с этой миграцией в дереве до коммита.
        if (! Schema::hasColumn('offers', 'migtorg_rejected')) {
            Schema::table('offers', fn (Blueprint $t) => $t->jsonb('migtorg_rejected')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('migtorg_rejected'));
    }
};
