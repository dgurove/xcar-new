<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки по дате письма, из которого заявка (владелец 07.10.2026): новые сверху. Письма нет — дата заявки.
 * Старым — первое входящее письмо их ветки, без ветки — письмо цепочки, которой завели ТС.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('park_requests', 'letter_at')) {
            Schema::table('park_requests', fn (Blueprint $t) => $t->timestamp('letter_at')->nullable());
        }
        DB::statement(<<<'SQL'
            update park_requests r set letter_at = coalesce(
                (select min(m.date_at) from mail_messages m where m.thread_id = r.thread_id and m.direction = 'in'),
                (select m.date_at from mail_candidates c join mail_messages m on m.id = c.message_id
                 where c.vehicle_id = r.vehicle_id and c.scope = 'park' and r.type in ('intake', 'tow') order by c.id limit 1)
            )
            where r.letter_at is null
        SQL);
        // Владелец сменил порядок для всех: запомненная сортировка «Заявок» («Срок») снимается, дальше каждый выбирает сам.
        DB::table('users')->whereNotNull('list_prefs')->orderBy('id')->each(function ($u) {
            $prefs = json_decode((string) $u->list_prefs, true);
            if (is_array($prefs) && isset($prefs['park-requests']['sort'])) {
                unset($prefs['park-requests']['sort']);
                DB::table('users')->where('id', $u->id)->update(['list_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('park_requests', fn (Blueprint $t) => $t->dropColumn('letter_at'));
    }
};
