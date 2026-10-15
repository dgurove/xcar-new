<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер отправлен вендору в запрос (15.10.2026, владелец каждый день шлёт Т-Страхованию номера убытка с интересом или
 * подтверждением): когда и кто. Отправленный в «К запросу» больше не попадает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('requested_at')->nullable()->index();
            $t->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn('requested_at');
        });
    }
};
