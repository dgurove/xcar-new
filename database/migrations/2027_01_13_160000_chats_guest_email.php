<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Почта гостя для ответа на «Написать нам» (13.10.2026, владелец: Антон написал «готов участвовать в торгах», мы ответили,
 * а ответ видно только в его браузере). Ответ сотрудника уходит ему письмом (`Chats\Jobs\SendGuestReply`). Прежним
 * гостевым обращениям — почта из первого сообщения, если человек вписал её в текст.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'guest_email')) {
            Schema::table('chats', fn (Blueprint $t) => $t->string('guest_email', 255)->nullable());
        }
        $chats = DB::table('chats')->whereNull('offer_id')->whereNull('user_id')->whereNull('guest_email')->pluck('id');
        foreach ($chats as $id) {
            $text = (string) DB::table('chat_messages')->where('chat_id', $id)->where('author_kind', 'participant')->orderBy('seq')->value('text');
            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m)) {
                DB::table('chats')->where('id', $id)->update(['guest_email' => mb_strtolower($m[0])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $t) => $t->dropColumn('guest_email'));
    }
};
