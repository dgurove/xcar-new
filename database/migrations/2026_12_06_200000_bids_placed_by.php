<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Подтверждение, внесённое админом за менеджера (05.10.2026: менеджер без интернета) — кто внёс. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $t) {
            $t->foreignId('placed_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bids', fn (Blueprint $t) => $t->dropConstrainedForeignId('placed_by'));
    }
};
