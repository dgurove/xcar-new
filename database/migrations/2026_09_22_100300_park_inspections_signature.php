<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Подпись сдавшего или получившего — PNG на закрытом диске, в акте печатается над линией. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_inspections', fn (Blueprint $t) => $t->string('signature_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('park_inspections', fn (Blueprint $t) => $t->dropColumn('signature_path'));
    }
};
