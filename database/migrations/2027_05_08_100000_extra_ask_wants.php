<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. запрос по просьбе менеджера (27.11.2026, Роман Корабельников: «рядом с „Беру в работу“ — „Доп. запрос…“, галочками что
 * запросить»; владелец выбрал из макетов А, 2б, 4б). Признак менеджера — кнопка есть только у него (пока Роману), просьба — та же
 * запись доп. просьбы до письма: кто попросил, какие галки (ид шаблонов `request`), комментарий; `since_at` пуст, пока письма нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('extra_ask_button')->default(false));
        DB::table('users')->where('name', 'ilike', '%Корабельников%')->whereJsonContains('roles', 'manager')->update(['extra_ask_button' => true]);
        Schema::table('extra_asks', function (Blueprint $t) {
            $t->timestamp('since_at')->nullable()->change();
            $t->foreignId('wanted_by')->nullable()->after('asked_by')->constrained('users')->nullOnDelete();
            $t->jsonb('picks')->nullable()->after('wanted_by');
            $t->string('comment', 500)->nullable()->after('picks');
        });
    }

    public function down(): void
    {
        Schema::table('extra_asks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('wanted_by');
            $t->dropColumn(['picks', 'comment']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('extra_ask_button'));
    }
};
