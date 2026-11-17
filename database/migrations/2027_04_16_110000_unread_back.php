<?php

use App\Mail\Jobs\PushSeen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Прочитанное — только то, что открыли люди (17.11.2026, владелец: «нахера ты ВСЕ письма прочитанными пометил? Я теперь не понимаю,
 * что мы прочитали, а что нет»). `mail_outside_and_crm_reading` пометила прочитанными все входящие извне старше трёх дней, и в ящиках
 * тоже. Список тех писем — по дампу перед той выкладкой (`db-20261117-030425`: не прочитаны тогда, прочитаны сейчас, извне, старше
 * 14.11 03:05) — в `database/data/unread-back-20261117.txt`: им снова «не прочитано», в базе и в ящиках (`PushSeen`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $file = database_path('data/unread-back-20261117.txt');
        $ids = collect(is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [])->map(fn ($v) => (int) $v)->filter();
        foreach ($ids->chunk(500) as $chunk) {
            $back = DB::table('mail_messages')->whereIn('id', $chunk)->where('direction', 'in')->where('is_seen', true)->pluck('id');
            if ($back->isEmpty()) {
                continue;
            }
            DB::table('mail_messages')->whereIn('id', $back)->update(['is_seen' => false]);
            PushSeen::dispatch($back->values()->all(), false);
        }
        DB::statement("update mail_threads t set unread_count = (select count(*) from mail_messages m where m.thread_id = t.id
            and m.direction = 'in' and not m.is_seen)");
    }

    public function down(): void {}
};
