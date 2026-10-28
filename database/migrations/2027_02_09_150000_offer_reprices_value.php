<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оценочная к переоценке (28.10.2026): у вендора «Закупочная от оценочной» (Альфа) страховая присылает оценочную —
 * закупочная считается от неё (`Pricing::fromAmount`), и обе встают при «Применить».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_reprices', fn (Blueprint $t) => $t->bigInteger('value')->nullable()->after('to_price'));
    }

    public function down(): void
    {
        Schema::table('offer_reprices', fn (Blueprint $t) => $t->dropColumn('value'));
    }
};
