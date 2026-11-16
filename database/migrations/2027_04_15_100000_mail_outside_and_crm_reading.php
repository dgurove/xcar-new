<?php

use App\Mail\Account;
use App\Mail\Jobs\PushSeen;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Почта по письму (17.11.2026, владелец: «во входящих только входящие, в отправленных только отправленные»):
 * `mail_messages.outside` — письмо извне (`Message::isFromVendor`: входящее не от нас или пересылка сотрудником чужого
 * письма), по нему стоят «Входящие» и «Отправленные»; дальше его держит `Message::saving`.
 *
 * CRM — без «Требуют внимания» («я бы вообще от этого явления избавился, чисто с прочитанными и непрочитанными»):
 * «ждёт ответа» у веток CRM снимается (дальше `Threads::waiting` его там не ставит), входящие CRM старше трёх дней —
 * прочитанными, и в ящике тоже (`PushSeen`): иначе «Входящие» открылись бы с 470 непрочитанными. Старые входящие
 * парковки — тоже: у неё те же «Входящие».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->boolean('outside')->default(false);
            $table->index(['account_id', 'outside', 'date_at'], 'mail_messages_box_index');
        });
        Message::query()->where('direction', 'in')->select(['id', 'direction', 'from_email', 'parsed'])
            ->chunkById(500, function ($chunk) {
                $ids = $chunk->filter(fn (Message $m) => $m->isFromVendor())->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('mail_messages')->whereIn('id', $ids)->update(['outside' => true]);
                }
            });

        $crm = Account::where('scope', Scope::Offers)->pluck('id');
        DB::table('mail_threads')->whereIn('account_id', $crm)->whereNotNull('needs_reply_at')->update(['needs_reply_at' => null]);
        // Прочитанными — старые входящие обеих сторон: на парковке те же «Входящие» (там висело 109 непрочитанных).
        $old = DB::table('mail_messages')->where('outside', true)->where('is_seen', false)
            ->where('date_at', '<', now()->subDays(3))->pluck('id');
        foreach ($old->chunk(500) as $ids) {
            DB::table('mail_messages')->whereIn('id', $ids)->update(['is_seen' => true]);
            PushSeen::dispatch($ids->values()->all(), true);
        }
        // Адрес, склеенный с закодированным именем («=?utf-8?b?…?=<6691111@mail.ru»), — адрес и имя порознь (`Parser::glued`).
        foreach (DB::table('mail_addresses')->where('email', 'like', '%<%@%')->get(['id', 'email', 'name']) as $row) {
            // Имя в базе уже строчными (закодированное base64 не раскодировать) — только адрес.
            DB::table('mail_addresses')->where('id', $row->id)->update(['email' => mb_strtolower(\App\Mail\Parser::glued($row->email)[0])]);
        }
        // Число непрочитанных у ветки — тем же правилом, что `Threads::refresh` (входящие, не прочитаны).
        DB::statement("update mail_threads t set unread_count = (select count(*) from mail_messages m where m.thread_id = t.id
            and m.direction = 'in' and not m.is_seen)");
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex('mail_messages_box_index');
            $table->dropColumn('outside');
        });
    }
};
