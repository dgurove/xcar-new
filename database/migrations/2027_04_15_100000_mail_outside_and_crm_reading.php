<?php

use App\Mail\Account;
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
 * «ждёт ответа» у веток CRM снимается (дальше `Threads::waiting` его там не ставит). Прочитанность не трогается: пометка
 * старых входящих прочитанными стёрла, что люди правда прочитали (17.11.2026, `2027_04_16_110000_unread_back`).
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
        // Старые входящие прочитанными больше не помечаются (17.11.2026, владелец: «не понимаю, что мы прочитали, а что нет»):
        // так было на проде при выкладке, вернули по дампу — `2027_04_16_110000_unread_back`.
        // Адрес, склеенный с закодированным именем («=?utf-8?b?…?=<6691111@mail.ru»), — адрес и имя порознь (`Parser::glued`).
        foreach (DB::table('mail_addresses')->where('email', 'like', '%<%@%')->get(['id', 'email', 'name']) as $row) {
            // Имя в базе уже строчными (закодированное base64 не раскодировать) — только адрес.
            DB::table('mail_addresses')->where('id', $row->id)->update(['email' => mb_strtolower(\App\Mail\Parser::glued($row->email)[0])]);
        }
        // Его копии: «Кому» письма (`to_preview`) — заново из адресов, участники ветки — пересчётом ветки; пустая закодированная
        // тема «=?UTF-8?B??=» — пустая тема.
        foreach (Message::where('to_preview', 'like', '%=?%')->pluck('id') as $id) {
            $to = DB::table('mail_addresses')->where('message_id', $id)->where('kind', 'to')->orderBy('position')->get(['email', 'name']);
            DB::table('mail_messages')->where('id', $id)->update(['to_preview' => $to->isEmpty() ? null
                : mb_substr($to->map(fn ($a) => $a->name ? "{$a->name} <{$a->email}>" : $a->email)->implode(', '), 0, 255)]);
        }
        DB::table('mail_messages')->where('subject', 'like', '=?%??=')->update(['subject' => null]);
        DB::table('mail_threads')->where('subject', 'like', '=?%??=')->update(['subject' => null]);
        foreach (\App\Mail\Thread::withoutGlobalScopes()->whereRaw("participants::text like '%=?%'")->get() as $thread) {
            app(\App\Mail\Threads::class)->refresh($thread);
        }
        // Наша цитата в своих словах письма («Исходное письмо, 14.11.2026 10:14, …:» и письмо под ней) — долой (`Intent::withoutOurQuote`).
        // И шапка цитаты mail.ru («Четверг, 12 ноября 2026, 12:29 +03:00 от …») — `Intent::withoutQuoteHeader`.
        foreach (Message::whereRaw("parsed->>'own_text' ~ '(Исходное|Пересылаемое) письмо, [0-9]{2}\\.'")
            ->orWhereRaw("parsed->>'own_text' ~* '(понедельник|вторник|среда|четверг|пятница|суббота|воскресенье), [0-9]{1,2} [а-яё]+ [0-9]{4}'")->get(['id', 'parsed']) as $m) {
            $parsed = $m->parsed;
            $parsed['own_text'] = \App\Mail\Extraction\Intent::withoutQuoteHeader(\App\Mail\Extraction\Intent::withoutOurQuote((string) $parsed['own_text']));
            DB::table('mail_messages')->where('id', $m->id)->update(['parsed' => json_encode($parsed, JSON_UNESCAPED_UNICODE)]);
        }
        // Адрес пересылки с приставкой «mailto:» (`QuotationStripper::forwardedSender`) — без неё, вендор отправителя — по адресу.
        // Письмо заново не перечитывается: цепочки не трогаем.
        foreach (Message::whereRaw("parsed->'fields'->'sender'->>'value' ilike 'mailto:%'")->get(['id', 'parsed', 'account_id']) as $m) {
            $parsed = $m->parsed;
            $sender = mb_strtolower((string) preg_replace('/^mailto:/i', '', (string) $parsed['fields']['sender']['value']));
            $parsed['fields']['sender']['value'] = $sender;
            $scope = \App\Mail\Account::find($m->account_id)?->scope ?? \App\Mail\Scope::Offers;
            if (empty($parsed['fields']['vendor_id']) && ($vendor = \App\Vendors\Vendor::forSender($sender, $scope))) {
                $parsed['fields']['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
                $parsed['fields']['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
            }
            DB::table('mail_messages')->where('id', $m->id)->update(['parsed' => json_encode($parsed, JSON_UNESCAPED_UNICODE)]);
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
