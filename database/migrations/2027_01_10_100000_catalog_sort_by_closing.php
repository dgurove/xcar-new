<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Каталог по умолчанию — по сроку приёма (владелец 10.10.2026): запомненная сортировка сбрасывается, умолчание доходит до всех. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update users set list_prefs = list_prefs #- '{catalog,sort}' where list_prefs -> 'catalog' is not null");
    }

    public function down(): void {}
};
