<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кадр, скачанный с сайта Мигторга, назван не uuid кадра, а именем файла в их хранилище (`file_name`:
 * `cbc721ee-…_watermark.webp`), оригинал же отдаётся по uuid. Индекс — пара: файл → кадр и лот. Пересобирается
 * синхронизацией и карточками, поэтому прежний просто заменяется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('migtorg_media');
        Schema::create('migtorg_media', function (Blueprint $t) {
            $t->uuid('file')->primary();
            $t->uuid('uuid')->index();
            $t->unsignedBigInteger('lot_id')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migtorg_media');
        Schema::create('migtorg_media', function (Blueprint $t) {
            $t->uuid('uuid')->primary();
            $t->unsignedBigInteger('lot_id')->index();
        });
    }
};
