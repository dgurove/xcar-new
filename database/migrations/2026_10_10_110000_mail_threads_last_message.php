<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Направление и смысл последнего письма ветки лежали коррелированными подзапросами: каждая пилюля почты
 * и каждый поиск перебирали письма заново, и экран отвечал больше секунды. Теперь это колонки ветки —
 * их держит `Threads::refresh` (он и так зовётся на каждое изменение писем, в том числе из `mail:read`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->string('last_direction', 4)->nullable();
            $t->string('last_intent', 20)->nullable();
            $t->index(['archived_at', 'last_direction', 'last_message_at'], 'mail_threads_box_index');
            $t->index('last_intent');
        });
        DB::statement("
            update mail_threads t set
                last_direction = (select m.direction from mail_messages m where m.thread_id = t.id order by m.date_at desc, m.id desc limit 1),
                last_intent = (select m.intent from mail_messages m where m.thread_id = t.id and m.direction = 'in' order by m.date_at desc, m.id desc limit 1)
        ");
    }

    public function down(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->dropIndex('mail_threads_box_index');
            $t->dropIndex(['last_intent']);
            $t->dropColumn(['last_direction', 'last_intent']);
        });
    }
};
