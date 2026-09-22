<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** «Наличие» теперь таблицей по умолчанию: запомненный прежде вид «строками» сбрасывается, дальше выбор помнится. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update users set list_prefs = list_prefs #- '{park-vehicles,vid}' where list_prefs -> 'park-vehicles' is not null");
    }

    public function down(): void {}
};
