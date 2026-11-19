<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Напомнить через…» в ответе на контрпредложение (19.11.2026, владелец: «соглашается, напомнить позже или отказывается — такая
 * же схема, как при вопросе „Берёте в работу?“»): когда снова прислать менеджеру контрпредложение (`Offers\Actions\RemindCounter`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', fn (Blueprint $t) => $t->timestamp('counter_remind_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('bids', fn (Blueprint $t) => $t->dropColumn('counter_remind_at'));
    }
};
