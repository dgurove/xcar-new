<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доходит ли пуш (20.10.2026: у логиста 9 уведомлений лежали без доставки, и этого никто не видел). Сервер знает только,
 * что пуш принят (`last_sent_at`), — показала ли его система, говорит человек на «Пришло?» после пробного: `confirmed_at`
 * («Да») или `silent_at` («Нет»: на Mac Chrome бывает выключен в «Уведомлениях», включено «Фокусирование»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $t) {
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('silent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', fn (Blueprint $t) => $t->dropColumn(['last_sent_at', 'confirmed_at', 'silent_at']));
    }
};
