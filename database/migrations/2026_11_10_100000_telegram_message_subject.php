<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * О чём сообщение бота (`Notice::telegramSubject`, например `candidate:12`): когда предмет уходит — цепочку «Из писем»
 * завели или убрали в архив, — его сообщения удаляются у всех, кому ушли (`RetractCandidateNotices`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->string('subject', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', fn (Blueprint $table) => $table->dropColumn('subject'));
    }
};
