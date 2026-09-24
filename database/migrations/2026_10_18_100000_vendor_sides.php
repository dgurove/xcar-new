<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вендор делится на две стороны (24.09.2026): у парковки свои адреса и разбор заявок на приёмку (`park_senders`,
 * `park_parser`), у CRM — `senders`/`parser` для писем с предложениями; НДС хранения (`vat_included`) и НДС цен
 * предложений (`offers_include_vat`) — разные галки. Стартуют копией общих значений.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->jsonb('park_senders')->default('[]');
            $t->string('park_parser', 16)->default('generic');
            $t->boolean('offers_include_vat')->default(false);
        });
        DB::statement('update vendors set park_senders = senders, park_parser = parser, offers_include_vat = vat_included');
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['park_senders', 'park_parser', 'offers_include_vat']));
    }
};
