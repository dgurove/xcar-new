<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ни одно предложение без подтверждений (22.10.2026, владелец: «по горящему ни одного подтверждения — потеряли деньги»).
 * `no_bids_alerted_for` — за какой срок приёма уже звали менеджеров «Ни одного подтверждения» (продлили — позовут снова).
 * `intake_closed_at` — приём кончился без подтверждений, предложение вернулось в «Оцененные» с меткой «приём закрыт»;
 * гаснет, когда его снова опубликовали.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('no_bids_alerted_for')->nullable();
            $t->timestamp('intake_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['no_bids_alerted_for', 'intake_closed_at']));
    }
};
