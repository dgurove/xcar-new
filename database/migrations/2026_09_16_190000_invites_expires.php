<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Срок ссылки: одноразовая менеджерская действует до указанного момента. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('max_uses');
        });
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $table) => $table->dropColumn('expires_at'));
    }
};
