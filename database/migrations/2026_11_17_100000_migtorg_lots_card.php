<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Для шторки «Мигторг» в редакторе предложения: до когда торги (`ends_at`, из списка) и сколько кадров в карточке
 * лота (`photos`, пишет задача, открыв её) — «Докачать фото», когда взялись не все.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migtorg_lots', function (Blueprint $t) {
            $t->timestamp('ends_at')->nullable();
            $t->unsignedSmallInteger('photos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn(['ends_at', 'photos']));
    }
};
