<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Управляющий парковкой (24.09.2026, решение владельца): роль `parking` — только park.xcar, разделы сверх основы
 * галками (`users.access`: money, mail), без настроек. Зовут его ссылкой админа, где доступ задан заранее: те же
 * поля у ссылки. Парковка больше не раздел для любой роли: прежняя отметка `park` у не-админов снимается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $t) {
            $t->jsonb('access')->nullable();
            $t->foreignId('park_yard_id')->nullable()->constrained('park_yards')->nullOnDelete();
            $t->boolean('park_readonly')->default(false);
        });
        DB::statement("update users set access = '[]'::jsonb, park_yard_id = null, park_readonly = false where role <> 'admin'");
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $t) {
            $t->dropConstrainedForeignId('park_yard_id');
            $t->dropColumn(['access', 'park_readonly']);
        });
    }
};
