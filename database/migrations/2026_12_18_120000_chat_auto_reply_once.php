<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Автоответ — один раз на чат (06.10.2026, владелец: «Получили Ваше сообщение» через день раздражает). Прежние повторы
 * вычищаем: в каждом чате остаётся самый ранний, остальные удаляются строкой (мягкое удаление оставило бы в ленте
 * «сообщение удалено»). Непрочитанное у человека и время последнего сообщения пересчитываются; `messages_count` — нет:
 * по нему выдаётся следующий seq, пропуск номеров безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Staff без автора бывает и у сотрудника, которого удалили (`nullOnDelete`) — поэтому ещё и по началу текста.
        $auto = fn () => DB::table('chat_messages')->where('author_kind', 'staff')->whereNull('author_id')
            ->where(fn ($w) => $w->where('text', 'like', 'Добрый день! Получили Ваше сообщение%')->orWhere('text', 'like', 'Здравствуйте! Получили Ваше сообщение%'));
        $first = $auto()->selectRaw('chat_id, min(seq) as seq')->groupBy('chat_id')->get()->pluck('seq', 'chat_id');
        $extra = $auto()->get(['id', 'chat_id', 'seq'])->filter(fn ($m) => (int) $m->seq !== (int) $first[$m->chat_id]);
        if ($extra->isEmpty()) {
            return;
        }
        DB::table('chat_files')->whereIn('message_id', $extra->pluck('id'))->delete();
        DB::table('chat_messages')->whereIn('id', $extra->pluck('id'))->delete();

        foreach ($extra->pluck('chat_id')->unique() as $chatId) {
            $chat = DB::table('chats')->where('id', $chatId)->first();
            DB::table('chats')->where('id', $chatId)->update([
                'unread_for_user' => DB::table('chat_messages')->where('chat_id', $chatId)->where('author_kind', 'staff')->where('seq', '>', (int) $chat->read_seq_user)->whereNull('deleted_at')->count(),
                'last_message_at' => DB::table('chat_messages')->where('chat_id', $chatId)->max('created_at') ?? $chat->last_message_at,
            ]);
        }
    }
};
