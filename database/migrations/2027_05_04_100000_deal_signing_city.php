<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Город подписания договора у поставщика (25.11.2026, владелец: у Альфы подписание по записи только в Москве, в других городах
 * приходят в рабочее время без записи; менеджер указывает город, когда прикладывает документ об оплате). Пусто — не указан:
 * как раньше, по записи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->foreignId('signing_settlement_id')->nullable()->constrained('settlements')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropConstrainedForeignId('signing_settlement_id'));
    }
};
