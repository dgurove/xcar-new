<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Реквизиты фотографией» сняты (13.11.2026, владелец: «"внесём сами" не катит, они сами должны поля заполнять»): менеджер
 * заполняет поля покупателя сам (`x-deal.buyer-kind`), отметки «фото у нас» больше нет. Присланные снимки остаются в документах
 * контрагента (`card`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('buyer_scans_at'));
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('buyer_scans_at')->nullable());
    }
};
