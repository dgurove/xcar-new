<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Управляющий парковкой ведёт всю парковку (08.10.2026, решение владельца): галок «Деньги» и «Почта» больше нет,
 * своей парковки тоже — он за все. «Только приёмка» (`park_readonly`) осталась — помощник на воротах.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update users set access = access - 'money' - 'mail' where roles @> '[\"parking\"]'::jsonb and access is not null");
        DB::statement("update invites set access = null where role = 'parking'");
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_yard_id'));
        Schema::table('invites', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_yard_id'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('park_yard_id')->nullable()->constrained('park_yards')->nullOnDelete());
        Schema::table('invites', fn (Blueprint $t) => $t->foreignId('park_yard_id')->nullable()->constrained('park_yards')->nullOnDelete());
    }
};
