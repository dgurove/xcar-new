<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Заметки у перевозки нет: её не заполняла ни одна форма, а сохранение затирало (ревью 10.10.2026). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transports', 'note')) {
            Schema::table('transports', fn (Blueprint $t) => $t->dropColumn('note'));
        }
    }

    public function down(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->text('note')->nullable());
    }
};
