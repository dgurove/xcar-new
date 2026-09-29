<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Цвет своей (разовой) метки предложения: {название: цвет}. Метки справочника красит справочник. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->jsonb('tag_colors')->nullable()->after('tags'));
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('tag_colors'));
    }
};
