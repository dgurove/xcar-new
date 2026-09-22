<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоянка к факту: № полиса у ТС; закрытые цепочки «Из писем» (`closed`, `closed_at`) и заморозка писем
 * (`frozen_at` — распарсенное и файлы сняты, письмо не читается и не пересобирается).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicles', function (Blueprint $table) {
            $table->string('policy_no', 60)->nullable()->after('ref_key');
        });
        Schema::table('mail_candidates', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('policy_no'));
        Schema::table('mail_candidates', fn (Blueprint $t) => $t->dropColumn('closed_at'));
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropColumn('frozen_at'));
    }
};
