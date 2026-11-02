<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кто в эксклюзиве (`FirstLook`) — своим полем, а не «последняя просьба с эксклюзивом» (ревью 02.11.2026: после второй
 * просьбы плашка «Приём» называла не того). Открытым сейчас — тот, чья взятая оценка с эксклюзивом последняя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->foreignId('first_look_user_id')->nullable()->constrained('users')->nullOnDelete());
        foreach (DB::table('offers')->whereNotNull('first_look_until')->pluck('id') as $id) {
            DB::table('offers')->where('id', $id)->update(['first_look_user_id' => DB::table('offer_estimates')->where('offer_id', $id)
                ->whereNotNull('exclusive_days')->where('state', 'taken')->orderByDesc('id')->value('user_id')]);
        }
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('first_look_user_id'));
    }
};
