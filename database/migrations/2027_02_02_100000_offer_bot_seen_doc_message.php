<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21.10.2026: карточка бота предложений — два сообщения, заголовок и PDF для пересылки клиенту (`PdfCard`). Ушло из
 * продажи — убираются оба: второй номер сообщения здесь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_bot_seen', function (Blueprint $t) {
            $t->bigInteger('doc_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offer_bot_seen', function (Blueprint $t) {
            $t->dropColumn('doc_message_id');
        });
    }
};
