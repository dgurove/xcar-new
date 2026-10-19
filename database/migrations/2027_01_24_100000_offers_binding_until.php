<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Срок обязывающего предложения (19.10.2026, владелец: «очень не хватает в CRM и в логистике»): до когда поставщик
 * держит цену торгов. `binding_alerted_for` — за какой срок уже звенела паника (продлили — звенит снова).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('binding_until')->nullable()->index();
            $t->timestamp('binding_alerted_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['binding_until', 'binding_alerted_for']));
    }
};
