<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо, из которого заявка (владелец 07.10.2026): в строке «Заявок» — адрес того, кто написал, и время письма,
 * с первой секунды, ещё у силуэта. Старым — тем же поиском, что `letter_at`: первое входящее письмо ветки, без ветки —
 * письмо цепочки, которой завели ТС.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('park_requests', 'letter_id')) {
            Schema::table('park_requests', fn (Blueprint $t) => $t->foreignId('letter_id')->nullable()->constrained('mail_messages')->nullOnDelete());
        }
        DB::statement(<<<'SQL'
            update park_requests r set letter_id = coalesce(
                (select m.id from mail_messages m where m.thread_id = r.thread_id and m.direction = 'in' order by m.date_at, m.id limit 1),
                (select c.message_id from mail_candidates c
                 where c.vehicle_id = r.vehicle_id and c.scope = 'park' and r.type in ('intake', 'tow') order by c.id limit 1)
            )
            where r.letter_id is null
        SQL);
    }

    public function down(): void
    {
        Schema::table('park_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('letter_id'));
    }
};
