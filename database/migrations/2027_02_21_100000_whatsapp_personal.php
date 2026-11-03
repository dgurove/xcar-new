<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 03.11.2026, владелец: открытое всем — в группу WhatsApp, открытое не всем и просьбы оценить — менеджеру в личные. WhatsApp
 * у человека — тот, из которого он прислал код привязки (`users.whatsapp_*`, номер в xcar может быть другим).
 * `whatsapp_messages` вместо `whatsapp_sent`: строка — сообщение взято в отправку (`key` — он же ключ службы в Цюрихе против
 * дублей), `message_id` есть — ушло, нет — идёт или служба отказала (`attempts`). Строки не удаляются: вернувшаяся в продажу
 * машина второй раз не приходит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('whatsapp_jid', 64)->nullable()->unique();
            $t->string('whatsapp_phone', 15)->nullable();
            $t->timestamp('whatsapp_linked_at')->nullable();
        });
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('kind', 10);
            $t->foreignId('offer_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('estimate_id')->nullable()->constrained('offer_estimates')->cascadeOnDelete();
            $t->string('message_id', 64)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('sent_at')->nullable()->index();
            $t->index(['offer_id', 'kind']);
            $t->index('user_id');
            $t->index('estimate_id');
        });
        DB::statement("insert into whatsapp_messages (key, kind, offer_id, message_id, attempts, created_at, sent_at)
            select 'offer:' || offer_id, 'group', offer_id, message_id, attempts, created_at, sent_at from whatsapp_sent");
        Schema::drop('whatsapp_sent');
    }

    public function down(): void
    {
        Schema::create('whatsapp_sent', function (Blueprint $t) {
            $t->foreignId('offer_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('message_id', 64)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
        });
        DB::statement("insert into whatsapp_sent (offer_id, message_id, attempts, created_at, sent_at)
            select offer_id, message_id, attempts, created_at, sent_at from whatsapp_messages where kind = 'group'");
        Schema::drop('whatsapp_messages');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['whatsapp_jid', 'whatsapp_phone', 'whatsapp_linked_at']));
    }
};
