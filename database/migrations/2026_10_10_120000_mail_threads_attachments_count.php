<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Число вложений ветки считалось подзапросом на каждую строку списка (`withCount` через письма) — почти
 * секунда на экран: у иной ветки полторы сотни файлов. Теперь это колонка, её держит `Threads::refresh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->unsignedInteger('attachments_count')->default(0));
        DB::statement('
            update mail_threads t set attachments_count = coalesce((
                select count(*) from mail_attachments a join mail_messages m on m.id = a.message_id
                 where m.thread_id = t.id and a.is_inline = false), 0)
        ');
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropColumn('attachments_count'));
    }
};
