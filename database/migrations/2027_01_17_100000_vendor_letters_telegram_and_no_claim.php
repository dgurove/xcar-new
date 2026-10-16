<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 17.10.2026, владелец. `letters_to_telegram` — письмо вендора на deal@ сразу админам в Telegram («очень важно» про
 * АльфаСтрахование: их ответы по сделкам ждут часами). `claim_ref_optional` — у вендора бывает без номера убытка
 * («б/н»), как у ТИТ с Tenet T4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->boolean('letters_to_telegram')->default(false);
            $t->boolean('claim_ref_optional')->default(false);
        });
        DB::table('vendors')->whereNull('crm_vendor_id')->where('name', 'ilike', 'АльфаСтрахование%')->update(['letters_to_telegram' => true]);
        DB::table('vendors')->where('name', 'ТИТ')->update(['claim_ref_optional' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropColumn(['letters_to_telegram', 'claim_ref_optional']);
        });
    }
};
