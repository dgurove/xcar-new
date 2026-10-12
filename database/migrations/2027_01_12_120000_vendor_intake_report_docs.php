<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Документы в отчёт о приёме (12.10.2026): что вендору уходит сканами вместе с фото. Альфа Москва получает акт,
 * заявку, СТС, ПТС/ЭПТС и доверенность («Альфа акт 1 чанган алсвин 0367.PDF» и т. д.), остальные — только фото.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->jsonb('intake_report_docs')->nullable();
        });
        DB::table('vendors')->where('name', 'АльфаСтрахование')
            ->update(['intake_report_docs' => json_encode(['act', 'request', 'sts', 'pts', 'proxy'])]);
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('intake_report_docs'));
    }
};
