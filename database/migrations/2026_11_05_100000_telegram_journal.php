<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переписка бота (30.09.2026): всё, что бот отправил и получил, — для раздела Настройки → «Бот Telegram».
 * Истории у Telegram бот не спросит, поэтому журнал начинается с этой миграции; чаты привязанных заводятся
 * сразу, чтобы им можно было написать первым.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name')->nullable();
            $t->string('username')->nullable();
            $t->timestamp('last_message_at')->nullable()->index();
            $t->timestamp('left_at')->nullable();
            $t->timestamps();
        });

        Schema::create('telegram_messages', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('chat_id');
            $t->foreign('chat_id')->references('id')->on('telegram_chats')->cascadeOnDelete();
            $t->bigInteger('message_id')->nullable();
            $t->string('direction', 3);
            $t->string('kind', 16)->default('text');
            $t->text('text')->nullable();
            $t->json('keyboard')->nullable();
            $t->foreignId('reply_to')->nullable();
            $t->string('file_id')->nullable();
            $t->string('file_unique_id')->nullable();
            $t->string('file_name')->nullable();
            $t->string('file_mime')->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('failed')->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['chat_id', 'id']);
            $t->unique(['chat_id', 'message_id']);
        });

        $now = now();
        $rows = DB::table('users')->whereNotNull('telegram_chat_id')->get(['id', 'name', 'telegram_chat_id', 'telegram_username'])
            ->map(fn ($u) => ['id' => $u->telegram_chat_id, 'user_id' => $u->id, 'name' => $u->name, 'username' => $u->telegram_username, 'created_at' => $now, 'updated_at' => $now])
            ->keyBy('id');
        $owner = trim((string) config('xcar.telegram.owner_chat_id'));
        if ($owner !== '' && ! $rows->has((int) $owner)) {
            $rows[(int) $owner] = ['id' => (int) $owner, 'user_id' => null, 'name' => null, 'username' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows->isNotEmpty()) {
            DB::table('telegram_chats')->insert($rows->values()->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_chats');
    }
};
