<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Видит ли покупатель VIN — решает менеджер при показе (владелец 04.10.2026); по умолчанию скрыт. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('showings', fn ($t) => $t->boolean('show_vin')->default(false));
    }

    public function down(): void
    {
        Schema::table('showings', fn ($t) => $t->dropColumn('show_vin'));
    }
};
