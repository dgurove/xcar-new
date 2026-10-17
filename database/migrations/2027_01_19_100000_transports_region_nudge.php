<?php

use App\Logistics\Transport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Логистика 19.01.2027: регион задачи — колонкой (чип «Регион» и назначение пачкой: «Самарская → все → Татьяне»), ставит
 * `Transport::saving` и правка города предложения; `nudged_at` — напоминание по шагу ушло (`logistics:tick`), сбрасывает
 * смена исполнителя, созвон и состояние.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('nudged_at')->nullable();
        });
        Transport::with(['offer.settlement', 'vehicle'])->each(function (Transport $t) {
            $t->region_id = $t->regionFor();
            $t->saveQuietly();
        });
        // Уже висящие шаги (63 поручения менеджерам после переноса) напоминанием разом не будим: про них — сводка админам.
        DB::table('transports')->whereIn('state', ['logist', 'assigned'])->update(['nudged_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('region_id');
            $t->dropColumn('nudged_at');
        });
    }
};
