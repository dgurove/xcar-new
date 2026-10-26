<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контрпредложение (26.10.2026, начальник): в ответ на подтверждение админ называет свою цену, менеджер соглашается
 * (сразу сделка) или отказывается (подтверждение остаётся по своей цене).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $t) {
            $t->unsignedInteger('counter_amount')->nullable();
            $t->string('counter_comment', 500)->nullable();
            $t->foreignId('counter_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->timestamp('counter_at')->nullable();
            $t->string('counter_state', 16)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $t) {
            $t->dropConstrainedForeignId('counter_by');
            $t->dropColumn(['counter_amount', 'counter_comment', 'counter_at', 'counter_state']);
        });
    }
};
