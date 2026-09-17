<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Чат как мессенджер: до какого номера каждая сторона дочитала (галочки), ответ на сообщение,
// правка и удаление (текст остаётся — сотрудник в CRM его видит), «в сети» у человека.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->unsignedInteger('read_seq_user')->default(0);
            $table->unsignedInteger('read_seq_staff')->default(0);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedInteger('reply_to')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('seen_at')->nullable());
        // Уже прочитанное считается дочитанным до конца.
        DB::statement('update chats set read_seq_user = case when unread_for_user = 0 then messages_count else greatest(messages_count - unread_for_user, 0) end, read_seq_staff = case when unread_for_staff = 0 then messages_count else greatest(messages_count - unread_for_staff, 0) end');
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn(['read_seq_user', 'read_seq_staff']));
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropColumn(['reply_to', 'edited_at', 'deleted_at']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('seen_at'));
    }
};
