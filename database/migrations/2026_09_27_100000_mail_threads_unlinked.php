<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ветку отвязали руками — следующее письмо не должно привязать её снова по номеру или VIN. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->timestamp('unlinked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropColumn('unlinked_at'));
    }
};
