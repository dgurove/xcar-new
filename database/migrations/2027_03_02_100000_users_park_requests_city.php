<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Город «Заявок» у человека парковки (05.11.2026, Сергей Николаев: питерские заявки «вообще не касаются», а счета и акты
 * ведёт по обоим городам): видит весь парк, а список заявок — одного города (`Park\City::requestsList`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('park_requests_city_id')->nullable()->constrained('settlements')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_requests_city_id'));
    }
};
