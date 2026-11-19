<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Напомнить через…» (19.11.2026, владелец: вместо тихой «Я передумал» по центру — две кнопки, «Я передумал» и «Напомнить
 * через…»): когда снова прислать менеджеру вопрос шага (`requirements`) или доп. просьбы (`extra_asks`) — уведомлением и
 * сообщением в чат (`Workflow\Actions\RemindAsks`). Ответил — напоминание гаснет вместе с вопросом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirements', fn (Blueprint $t) => $t->timestamp('remind_at')->nullable());
        Schema::table('extra_asks', fn (Blueprint $t) => $t->timestamp('remind_at')->nullable());
        Schema::table('requirements', fn (Blueprint $t) => $t->index('remind_at'));
    }

    public function down(): void
    {
        Schema::table('requirements', fn (Blueprint $t) => $t->dropColumn('remind_at'));
        Schema::table('extra_asks', fn (Blueprint $t) => $t->dropColumn('remind_at'));
    }
};
