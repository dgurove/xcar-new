<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Продана на аукционе» (20.10.2026, Совкомбанк): сделка без менеджера — когда продана и кто купил (контрагент счёта).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('auction_at')->nullable();
            $t->foreignId('buyer_party_id')->nullable()->index()->constrained('billing_parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('buyer_party_id');
            $t->dropColumn('auction_at');
        });
    }
};
