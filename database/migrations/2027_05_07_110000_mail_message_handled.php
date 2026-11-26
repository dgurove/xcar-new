<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо дообработано (26.11.2026, владелец: «после выкладки сразу продолжать»): разбор, убитый после записи письма, но до конца
 * следствий (ветка с файлами, уведомления, ход сделки), раньше не повторялся никогда. `handled_at` — следствия прошли до конца
 * (`Ingest::handle`); пустое старше трёх минут дочитывает `mail:sync`. Побочные шаги, которые повторять нельзя (уведомление, строка
 * истории, письмо поставщику), — по одному разу на письмо (`mail_message_steps`, `MessageStep::once`). Прежние письма — дообработаны:
 * история не переигрывается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $t) {
            $t->timestamp('handled_at')->nullable();
        });
        DB::statement('update mail_messages set handled_at = created_at');
        // Письма, что прежний код примет, пока выкладка меняет работников, отметки не получат, и их шаги прошли без отметок «один
        // раз»: дочитка берёт только письма после этой минуты с запасом (`SyncMail::resume`), иначе задвоила бы их уведомления.
        Cache::forever('mail:resume:since', now()->addMinutes(10)->getTimestamp());
        DB::statement('create index mail_messages_unhandled on mail_messages (created_at) where handled_at is null');
        Schema::create('mail_message_steps', function (Blueprint $t) {
            $t->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $t->string('step', 80);
            $t->boolean('result')->default(true);
            $t->timestamp('created_at')->useCurrent();
            $t->primary(['message_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_message_steps');
        DB::statement('drop index if exists mail_messages_unhandled');
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropColumn('handled_at'));
    }
};
