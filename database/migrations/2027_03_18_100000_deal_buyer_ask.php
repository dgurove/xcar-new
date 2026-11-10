<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Покупатель — дело менеджера с согласия (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу, а не когда
 * страховая запросила»): когда попросили (`buyer_asked_at`) и до какого срока (`buyer_due_at`), гаснет, когда покупатель готов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('buyer_asked_at')->nullable();
            $t->timestamp('buyer_due_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['buyer_asked_at', 'buyer_due_at']));
    }
};
