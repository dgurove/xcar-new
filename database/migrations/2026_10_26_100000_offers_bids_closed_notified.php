<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Про какой срок приёма владельцу уже написали «приём закрыт»: срок продлили — напишем заново. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->timestamp('bids_closed_notified_for')->nullable()->after('bids_close_at'));
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('bids_closed_notified_for'));
    }
};
