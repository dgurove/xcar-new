<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 03.11.2026: бот предложений больше ничего не спрашивает — каждое новое предложение одной карточкой (`Telegram\Offers\Cards`).
 * Ленты, меню, вопросов и приглашений нет: их состояние уходит. `offer_bot_seen` → `offer_bot_sent` — что отправлено и
 * каким сообщением; у прежних карточек из двух сообщений помним PDF (в нём цена), его и уберём, когда машина уйдёт.
 * `cards_since` — с какого момента слать: нынешним подписчикам — с выкладки, иначе им ушли бы все машины в продаже.
 * `telegram_files` (кадры для карточки «одно фото») и `offer_bot_links`, `chat_messages.via_bot` (вопросы 💬) больше не нужны.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('update offer_bot_seen set message_id = doc_message_id where doc_message_id is not null');
        Schema::rename('offer_bot_seen', 'offer_bot_sent');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_seen_pkey to offer_bot_sent_pkey');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_seen_offer_id_foreign to offer_bot_sent_offer_id_foreign');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_seen_user_id_foreign to offer_bot_sent_user_id_foreign');
        // Postgres 18 называет и NOT NULL.
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_seen_offer_id_not_null to offer_bot_sent_offer_id_not_null');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_seen_user_id_not_null to offer_bot_sent_user_id_not_null');
        DB::statement('alter index offer_bot_seen_offer_id_fk_index rename to offer_bot_sent_offer_id_fk_index');
        Schema::table('offer_bot_sent', function (Blueprint $t) {
            $t->dropColumn(['reaction', 'doc_message_id']);
        });

        Schema::table('offer_bot_chats', function (Blueprint $t) {
            $t->dropConstrainedForeignId('offer_id');
            $t->dropColumn(['mode', 'muted_at', 'remind_at', 'announced_at', 'morning_on', 'payload', 'prompt_message_id']);
            $t->timestamp('cards_since')->useCurrent();
        });

        Schema::dropIfExists('offer_bot_links');
        Schema::dropIfExists('telegram_files');
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->dropColumn('via_bot');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->boolean('via_bot')->default(false);
        });
        Schema::create('telegram_files', function (Blueprint $t) {
            $t->id();
            $t->string('bot', 16);
            $t->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $t->unsignedBigInteger('version');
            $t->string('file_id');
            $t->timestamp('created_at')->nullable();
            $t->unique(['bot', 'media_id', 'version']);
        });
        Schema::create('offer_bot_links', function (Blueprint $t) {
            $t->bigInteger('tg_chat_id');
            $t->bigInteger('tg_message_id');
            $t->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $t->unsignedInteger('seq')->nullable();
            $t->string('kind', 12);
            $t->timestamp('created_at')->nullable();
            $t->primary(['tg_chat_id', 'tg_message_id']);
            $t->index(['chat_id', 'kind']);
        });

        Schema::table('offer_bot_chats', function (Blueprint $t) {
            $t->dropColumn('cards_since');
            $t->string('mode', 12)->default('menu');
            $t->foreignId('offer_id')->nullable()->index('offer_bot_chats_offer_id_fk_index')->constrained('offers')->nullOnDelete();
            $t->timestamp('muted_at')->nullable();
            $t->timestamp('remind_at')->nullable();
            $t->timestamp('announced_at')->nullable();
            $t->date('morning_on')->nullable();
            $t->jsonb('payload')->nullable();
            $t->bigInteger('prompt_message_id')->nullable();
        });

        Schema::table('offer_bot_sent', function (Blueprint $t) {
            $t->string('reaction', 8)->nullable();
            $t->bigInteger('doc_message_id')->nullable();
        });
        DB::statement('alter index offer_bot_sent_offer_id_fk_index rename to offer_bot_seen_offer_id_fk_index');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_sent_user_id_not_null to offer_bot_seen_user_id_not_null');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_sent_offer_id_not_null to offer_bot_seen_offer_id_not_null');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_sent_user_id_foreign to offer_bot_seen_user_id_foreign');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_sent_offer_id_foreign to offer_bot_seen_offer_id_foreign');
        DB::statement('alter table offer_bot_sent rename constraint offer_bot_sent_pkey to offer_bot_seen_pkey');
        Schema::rename('offer_bot_sent', 'offer_bot_seen');
    }
};
