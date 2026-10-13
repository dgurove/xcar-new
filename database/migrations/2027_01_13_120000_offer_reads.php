<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Просмотрено» на карточках сайта (13.10.2026, менеджеры): кто какое предложение уже открывал. Пара — ключ, строка —
 * два числа и время (≈ 40 байт); читается подзапросом по ключу в `CatalogQuery::cards`, ночью чистится по закрытым.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_reads', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offer_id')->index()->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->primary(['user_id', 'offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_reads');
    }
};
