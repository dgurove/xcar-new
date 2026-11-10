<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дела менеджера там, где он смотрит (10.11.2026, владелец: «ни один менеджер нормально системой не начал пользоваться»):
 * сообщение площадки в чате сделки несёт кнопки просьбы шага (`requirement_id`), «Забрали автомобиль?» при входе
 * спрашивается раз в день (`prompted_at`), реквизиты покупателя фотографией — ход наш, пока их не внесли (`buyer_scans_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->foreignId('requirement_id')->nullable()->constrained('requirements')->nullOnDelete();
        });
        Schema::table('requirements', function (Blueprint $t) {
            $t->timestamp('prompted_at')->nullable();
        });
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('buyer_scans_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('requirement_id'));
        Schema::table('requirements', fn (Blueprint $t) => $t->dropColumn('prompted_at'));
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('buyer_scans_at'));
    }
};
