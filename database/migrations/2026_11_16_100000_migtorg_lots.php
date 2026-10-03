<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лоты migtorg.com по номеру дела (03.10.2026): индекс для фото предложений. Номера всех лотов отдаёт открытый список
 * (`migtorg:sync`), карточку с полным набором фото — только с входом, её берут у совпавших. Ушедший с торгов лот
 * остаётся с `gone_at`: предложение по нему могут завести позже. `offer_id` — кому фото уже взяты.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migtorg_lots', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('claim_ref', 80);
            $t->string('claim_ref_key', 80)->nullable()->index();
            $t->string('vin', 20)->nullable();
            $t->string('title', 160)->nullable();
            $t->timestamp('lot_updated_at')->nullable();
            $t->timestamp('seen_at');
            $t->timestamp('gone_at')->nullable();
            $t->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migtorg_lots');
    }
};
