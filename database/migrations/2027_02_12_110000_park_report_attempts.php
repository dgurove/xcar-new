<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отчёт о приёме (29.10.2026, разбор после выкладки): `attempt` — номер сборки; задача сборки берёт отчёт, только если номер
 * её (опоздавшая задача после «Повторить» второй комплект писем не соберёт). Письмо → отчёт — индексом по `messages`, а не
 * перебором таблицы на каждое отправленное письмо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_reports', function (Blueprint $t) {
            $t->unsignedInteger('attempt')->default(1);
        });
        DB::statement('create index park_reports_messages_index on park_reports using gin (messages jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists park_reports_messages_index');
        Schema::table('park_reports', fn (Blueprint $t) => $t->dropColumn('attempt'));
    }
};
