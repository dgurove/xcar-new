<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лот Мигторга целиком (`data` — `lot` и конец торгов из строки списка или карточки): поля предложения встают из индекса
 * сразу, без карточки со входом. `migtorg_media` — кадр → лот: кадр, скачанный с их сайта (`{uuid}_watermark.webp`),
 * сам называет лот.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->jsonb('data')->nullable());
        Schema::create('migtorg_media', function (Blueprint $t) {
            $t->uuid('uuid')->primary();
            $t->unsignedBigInteger('lot_id')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migtorg_media');
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn('data'));
    }
};
