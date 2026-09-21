<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Смысл письма (`intent`: приём, продано, осмотр, бумаги, принято, не вывезено, вопрос) и этапы цепочки кандидата
 * (`stages` — что было по письмам: заявка, принята, продана; `stage` — последний, для чипа и фильтра).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', fn (Blueprint $t) => $t->string('intent', 20)->nullable());
        Schema::table('mail_candidates', function (Blueprint $t) {
            $t->jsonb('stages')->default('[]');
            $t->string('stage', 20)->default('intake')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropColumn('intent'));
        Schema::table('mail_candidates', fn (Blueprint $t) => $t->dropColumn(['stages', 'stage']));
    }
};
