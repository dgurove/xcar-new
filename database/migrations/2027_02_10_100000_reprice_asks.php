<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закупочная выросла — вопрос менеджеру (28.10.2026, владелец: «просто ставим перед фактом, это неправильно»): по каждому
 * подтверждению и сделке, где админ выбрал «Обновить», — `Offers\RepriceAsk`, пока менеджер не ответит «Согласен» или
 * «Отказываюсь» (или админ за него). Цена у него до ответа прежняя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reprice_asks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reprice_id')->constrained('offer_reprices')->cascadeOnDelete();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bid_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('from_price');
            $t->unsignedBigInteger('to_price');
            // Что менеджер видит: у подтверждения — цена «от», у ДКП и гаража — закупочная, у остальных сделок — null (только разница).
            $t->unsignedBigInteger('shown_from')->nullable();
            $t->unsignedBigInteger('shown_to')->nullable();
            $t->string('state', 12)->default('pending')->index();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reprice_asks');
    }
};
