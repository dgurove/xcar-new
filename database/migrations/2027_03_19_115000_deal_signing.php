<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Запись на подписание у поставщика (11.11.2026, Альфа): его свободное время, адрес филиала, выбранное менеджером время.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->jsonb('signing_slots')->nullable();
            $t->string('signing_address', 500)->nullable();
            $t->timestamp('signing_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['signing_slots', 'signing_address', 'signing_at']));
    }
};
