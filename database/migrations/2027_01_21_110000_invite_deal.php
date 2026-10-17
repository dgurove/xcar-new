<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 18.10.2026: ссылку покупателю можно сделать прямо из сделки (покупатель ДКП ещё не заведён) — пришёл по ней, и он
 * покупатель этой сделки сам (`AcceptInvite`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', fn (Blueprint $t) => $t->foreignId('deal_id')->nullable()->index()->constrained('deals')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $t) => $t->dropConstrainedForeignId('deal_id'));
    }
};
