<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Публикация «Срочно» (12.10.2026, владелец: страховая ждёт ответ к завтрашнему полудню): открыто всем сразу, громкое
 * уведомление, огонёк до конца приёма. Когда и кто отметил.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('urgent_at')->nullable();
            $t->foreignId('urgent_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('urgent_by');
            $t->dropColumn('urgent_at');
        });
    }
};
