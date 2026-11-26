<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История документа шага «Оригиналы» (26.11.2026, владелец выбрал из макетов: «история с датами»): кто и когда отметил, где
 * документ, трек, приложил или убрал скан. Запись — `{state|scan, at, by, track?}` (`Original::note`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_originals', function (Blueprint $t) {
            $t->jsonb('history')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('deal_originals', fn (Blueprint $t) => $t->dropColumn('history'));
    }
};
