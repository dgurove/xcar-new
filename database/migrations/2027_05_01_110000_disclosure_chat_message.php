<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Показ менеджеру правится и удаляется (25.11.2026, владелец: «чтобы то сообщение удалилось и новое отправилось»): какое
 * сообщение чата сделки он отправил и с каким заголовком («Контакт для получения ТС»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_disclosures', function (Blueprint $t) {
            $t->unsignedBigInteger('chat_message_id')->nullable();
            $t->string('lead', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_disclosures', function (Blueprint $t) {
            $t->dropColumn(['chat_message_id', 'lead']);
        });
    }
};
