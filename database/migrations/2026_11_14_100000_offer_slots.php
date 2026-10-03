<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Слоты публикации (03.10.2026): предложение выходит не сразу, а в слот 16:00. `slot_at` у черновика и галереи —
 * «выйдет тогда-то», у открытого — «вышло слотом именно в этот раз» (боту и уведомлению на слот); любой другой
 * переход его обнуляет. `slot_by` — кто поставил, от его имени публикуют часы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('slot_at')->nullable()->index();
            $t->foreignId('slot_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('slot_by');
            $t->dropColumn('slot_at');
        });
    }
};
