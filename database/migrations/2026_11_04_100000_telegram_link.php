<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram у аккаунта (30.09.2026): менеджеру — уведомления по сделкам и вход, админу — сообщения владельца.
 * Один чат — один аккаунт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->bigInteger('telegram_chat_id')->nullable()->unique();
            $t->string('telegram_username')->nullable();
            $t->timestamp('telegram_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['telegram_chat_id', 'telegram_username', 'telegram_linked_at']));
    }
};
