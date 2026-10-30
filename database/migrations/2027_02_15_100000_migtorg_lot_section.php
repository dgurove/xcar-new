<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел лота Мигторга — `casco` / `osago` (`lot.insurance_type`). 30.10.2026, владелец: «с Мигторга только с раздела
 * каско, остальное мусор» — черновики сами заводятся только по КАСКО (`MigtorgDrafts`); ОСАГО-лот по-прежнему даёт поля
 * и фото предложению, заведённому руками, письмом или стоянкой. Живые лоты получат раздел первым же прогоном `migtorg:sync`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->string('section', 8)->nullable());
    }

    public function down(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn('section'));
    }
};
