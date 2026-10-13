<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «На аукцион» (13.10.2026): вкладка предложений после «Из таблицы Кати». `auction_at` — с какого момента черновик там,
 * `auction_planned_at` — «По истечению срока на аукцион» (уйдёт, если подтверждений не будет), `auction_by` — кто нажал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('auction_at')->nullable()->index();
            $t->timestamp('auction_planned_at')->nullable()->index();
            $t->foreignId('auction_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('auction_by');
            $t->dropColumn(['auction_at', 'auction_planned_at']);
        });
    }
};
