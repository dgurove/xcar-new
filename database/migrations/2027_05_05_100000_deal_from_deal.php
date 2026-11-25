<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Сделка, от которой перешла эта (переприём, 26.11.2026): с её начала считается «за время сделки» у переоценки (`Deal::since`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->foreignId('from_deal_id')->nullable()->constrained('deals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('from_deal_id');
        });
    }
};
