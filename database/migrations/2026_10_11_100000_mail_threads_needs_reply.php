<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Ждёт ответа» считалось в шести местах по-разному — пилюля в почте, шаг в деле ТС, точка в ленте, разбор
 * письма, строка почты, уведомление — и числа не совпадали нигде. Правило теперь одно и лежит колонкой:
 * последнее письмо ветки — их входящее с вопросом (осмотр, документы, «не выдавать», «не вывезено»), и после
 * него мы не писали и не нажимали «Сделано» (`answered_at`). Держит `Threads::refresh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->timestamp('needs_reply_at')->nullable()->index();
            $t->timestamp('answered_at')->nullable();
        });
        DB::statement("
            with last as (
                select distinct on (thread_id) thread_id, date_at, intent, direction
                  from mail_messages where thread_id is not null
                 order by thread_id, date_at desc, id desc
            )
            update mail_threads t set needs_reply_at = last.date_at
              from last
             where last.thread_id = t.id and last.direction = 'in'
               and last.intent in ('inspect', 'docs', 'question', 'hold', 'cancel_release')
        ");
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropColumn(['needs_reply_at', 'answered_at']));
    }
};
