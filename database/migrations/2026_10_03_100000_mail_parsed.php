<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо разбирается один раз: результат (`parsed`: поля, номера, смысл, свои слова) хранится у письма с версией
 * парсера; номера письма — в `mail_message_keys` (индекс для веток и цепочек). Всё остальное считается из этого.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $t) {
            $t->jsonb('parsed')->nullable();
            $t->unsignedSmallInteger('parser_version')->default(0)->index();
        });
        Schema::create('mail_message_keys', function (Blueprint $t) {
            $t->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $t->string('key', 80);
            $t->primary(['message_id', 'key']);
            $t->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_message_keys');
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropColumn(['parsed', 'parser_version']));
    }
};
