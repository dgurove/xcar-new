<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Дело «Укажите покупателя» без срока (11.11.2026, владелец: «Альфа может попросить реквизиты через месяц — зачем торопить»).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('buyer_due_at'));
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('buyer_due_at')->nullable());
    }
};
