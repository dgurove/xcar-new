<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21.10.2026, владелец выбрал «напоминание по избранному»: за 30 минут до конца приёма — громко о машинах в «Избранном» без
 * своего подтверждения (`RemindUnreadClosing::last`); отметка — чтобы не повторять.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->timestamp('last_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->dropColumn('last_reminded_at');
        });
    }
};
