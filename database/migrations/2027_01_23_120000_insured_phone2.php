<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Второй телефон владельца (19.10.2026, логистика: «добавить возможность указывать дополнительный номер»). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', fn (Blueprint $t) => $t->string('insured_phone2', 40)->nullable());
    }

    public function down(): void
    {
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('insured_phone2'));
    }
};
