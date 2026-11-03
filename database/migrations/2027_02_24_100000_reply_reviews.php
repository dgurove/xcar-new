<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Письма страховой — менеджеру только выбором админа (03.11.2026, владелец: «пришло письмо — менеджеру вообще ничего не
 * показываем и не говорим даже, что пришёл ответ»): `reply_reviews` — очередь «Разобрать ответ» (письмо по идущей сделке),
 * `deal_disclosures` — что админ показал менеджеру (`Offers\ReplyReview`, `Offers\Disclosure`). Прежние «Контакты
 * владельца» в ленте менеджеров стираются: показанное до сегодня не переносится (владелец: «убрать у всех»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('message_id')->unique()->constrained('mail_messages')->cascadeOnDelete();
            $t->string('state', 12)->default('pending');
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
            $t->index(['offer_id', 'state']);
            $t->index(['deal_id', 'state']);
        });

        Schema::create('deal_disclosures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->index()->constrained()->cascadeOnDelete();
            // [{kind: phone|name|address|text|file, value, media?}] — ровно то, что видит менеджер.
            $t->jsonb('items');
            $t->jsonb('message_ids')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        DB::table('notifications')->where('type', 'App\Notifications\InsurerReplyNotice')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_disclosures');
        Schema::dropIfExists('reply_reviews');
    }
};
