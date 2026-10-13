<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Предложено из заявок на приём» (13.10.2026, владелец): черновик, что ТС парковки выставила сама (`Park\Actions\AutoSale`),
 * ждёт решения админа — плашка «Принять / Отклонить» в окошке и редакторе. `proposed_at` — когда предложили, пусто — решено.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offers', 'proposed_at')) {
            Schema::table('offers', fn (Blueprint $t) => $t->timestamp('proposed_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('proposed_at'));
    }
};
