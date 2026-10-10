<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Карточка бота предложений помнит своё сообщение (10.10.2026, владелец: Ситрак ушёл в сделку через две минуты после
 * публикации, а карточка с фото и ценой осталась у менеджеров в Telegram): предложение ушло из продажи — бот её удаляет
 * (`Offers\Jobs\RetractOfferNotices`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_bot_seen', function (Blueprint $t) {
            $t->bigInteger('chat_id')->nullable();
            $t->bigInteger('message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offer_bot_seen', function (Blueprint $t) {
            $t->dropColumn(['chat_id', 'message_id']);
        });
    }
};
