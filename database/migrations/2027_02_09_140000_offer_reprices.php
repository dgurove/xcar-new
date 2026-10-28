<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переоценка закупочной на решение админа (28.10.2026, владелец: «по такому-то письму такая-то переоценка — принять или
 * отменить», «у тех, кто подтвердил, и в сделке — оставить прежнюю или обновить»). `Offers\Reprice`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_reprices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('from_price')->nullable();
            $t->bigInteger('to_price');
            $t->string('source', 16);
            $t->foreignId('message_id')->nullable()->index()->constrained('mail_messages')->nullOnDelete();
            $t->string('state', 16)->default('pending');
            $t->jsonb('choices')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['offer_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_reprices');
    }
};
