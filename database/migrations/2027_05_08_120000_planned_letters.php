<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Запланированное письмо поставщику (27.11.2026, начальник: «Спросить менеджера и запланировать письмо…» — письмо шага после
 * согласия пишем сразу, уходит само, как только менеджер нажмёт «Беру в работу»; `Offers\PlannedLetters`). Что ушло бы из формы
 * окна письма — `data`; шаблон этапа письма — по нему узнаём, что менеджер взял (вошли на этап с этим шаблоном). Ждущее на сделку
 * — одно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_letters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('mail_templates')->nullOnDelete();
            $t->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('data');
            $t->string('state', 12)->default('pending');
            $t->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $t->string('error', 500)->nullable();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['deal_id', 'state']);
        });
        DB::statement("create unique index planned_letters_one_pending on planned_letters (deal_id) where state = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_letters');
    }
};
