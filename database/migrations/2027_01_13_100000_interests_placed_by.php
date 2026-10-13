<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Интерес за менеджера (13.10.2026, владелец: менеджер сказал устно — админ вносит интерес к черновику в галерее сам,
 * как подтверждение «+ За менеджера»). Кто внёс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interests', function (Blueprint $t) {
            $t->foreignId('placed_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('placed_by');
        });
    }
};
