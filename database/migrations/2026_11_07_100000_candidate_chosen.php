<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «✨ Распознать» (01.10.2026): что человек выбрал из распознанного, лежит у цепочки отдельно от свёртки писем —
 * `ChainBuilder::fold` кладёт выбор поверх, и новое письмо его не затирает. OCR больше не ставится сам на каждое
 * письмо: задачи старой очереди `ocr` (`ReadDocuments`, класса больше нет) снимаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_candidates', function (Blueprint $table) {
            $table->jsonb('chosen')->nullable();
        });
        DB::table('jobs')->where('queue', 'ocr')->delete();
    }

    public function down(): void
    {
        Schema::table('mail_candidates', function (Blueprint $table) {
            $table->dropColumn('chosen');
        });
    }
};
