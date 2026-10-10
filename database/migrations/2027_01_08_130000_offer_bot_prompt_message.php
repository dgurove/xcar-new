<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Анонс бота предложений один на чат (10.10.2026): id последнего — чтобы следующий его удалил. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offer_bot_chats', 'prompt_message_id')) {
            Schema::table('offer_bot_chats', fn (Blueprint $t) => $t->bigInteger('prompt_message_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('offer_bot_chats', fn (Blueprint $t) => $t->dropColumn('prompt_message_id'));
    }
};
