<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бот предложений @xcar_offers_bot (03.10.2026): подписчики и их место в разговоре, что кому уже показали, кэш фото
 * в Telegram (file_id у каждого бота свой) и связь сообщений бота с чатами по предложению — ответ реплаем уходит в
 * тот же чат xcar. Сообщение чата, пришедшее из бота, помечено `via_bot`: ответы по этому чату идут обратно в бот.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_bot_chats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $t->bigInteger('chat_id')->unique();
            // Где человек в разговоре: menu | prompt (анонс «показать?») | feed (листает) | asking (пишет вопрос) | invite.
            $t->string('mode', 12)->default('menu');
            $t->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $t->timestamp('muted_at')->nullable();       // «Я больше не хочу получать предложения»
            $t->timestamp('blocked_at')->nullable();     // остановил бота
            $t->timestamp('remind_at')->nullable();      // «Напомнить через 1 ч»
            $t->timestamp('announced_at')->nullable();   // до какого момента открывшееся уже анонсировали
            $t->date('morning_on')->nullable();          // 13:00 «в 16:00 будет опубликовано» — последний день
            $t->jsonb('payload')->nullable();
            $t->timestamps();
        });

        Schema::create('offer_bot_seen', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $t->string('reaction', 8)->nullable();       // next | pin | ask | sleep
            $t->timestamp('created_at')->nullable();
            $t->primary(['user_id', 'offer_id']);
        });

        Schema::create('telegram_files', function (Blueprint $t) {
            $t->id();
            $t->string('bot', 16);
            $t->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $t->unsignedBigInteger('version');           // updated_at кадра: повернули — грузим заново
            $t->string('file_id');
            $t->timestamp('created_at')->nullable();
            $t->unique(['bot', 'media_id', 'version']);
        });

        Schema::create('offer_bot_links', function (Blueprint $t) {
            $t->bigInteger('tg_chat_id');
            $t->bigInteger('tg_message_id');
            $t->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $t->unsignedInteger('seq')->nullable();      // сообщение чата xcar, которое это сообщение бота несёт
            $t->string('kind', 12);                      // question | answer | ask
            $t->timestamp('created_at')->nullable();
            $t->primary(['tg_chat_id', 'tg_message_id']);
            $t->index(['chat_id', 'kind']);
        });

        Schema::table('chat_messages', function (Blueprint $t) {
            $t->boolean('via_bot')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn('via_bot'));
        Schema::dropIfExists('offer_bot_links');
        Schema::dropIfExists('telegram_files');
        Schema::dropIfExists('offer_bot_seen');
        Schema::dropIfExists('offer_bot_chats');
    }
};
