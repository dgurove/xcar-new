<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** «Наличие» таблицей: запомненный вид «строками» сбрасывается ещё раз (после выкладки 22.09 он успел сохраниться заново). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update users set list_prefs = list_prefs #- '{park-vehicles,vid}' where list_prefs -> 'park-vehicles' is not null");
    }

    public function down(): void {}
};
