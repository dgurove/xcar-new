<?php

use App\Mail\Extraction\Code;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кандидат из писем — одна ТС, много писем. Тождество ТС в почте — `key`
 * (убыток → VIN → госномер → ветка), письма — пивотом `mail_candidate_messages`;
 * дубли среди ждущих сливаются в самого раннего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_candidates', function (Blueprint $t) {
            $t->string('key', 80)->nullable()->index();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->unsignedSmallInteger('messages_count')->default(1);
            $t->timestamp('last_message_at')->nullable();
        });
        Schema::create('mail_candidate_messages', function (Blueprint $t) {
            $t->foreignId('candidate_id')->constrained('mail_candidates')->cascadeOnDelete();
            $t->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['candidate_id', 'message_id']);
        });

        DB::transaction(function () {
            $rows = DB::table('mail_candidates')->orderBy('id')->get();
            foreach ($rows as $c) {
                $x = json_decode($c->extracted ?: '{}', true) ?: [];
                $value = fn (string $f) => $x[$f]['value'] ?? null;
                $key = match (true) {
                    (bool) $c->code => 'code:'.Code::key($c->code),
                    (bool) $value('vin') => 'vin:'.strtoupper((string) $value('vin')),
                    (bool) $value('plate') => 'plate:'.mb_strtoupper((string) $value('plate')),
                    (bool) $c->thread_id => 'thread:'.$c->thread_id,
                    default => 'message:'.$c->message_id,
                };
                $at = DB::table('mail_messages')->where('id', $c->message_id)->value('date_at');
                DB::table('mail_candidates')->where('id', $c->id)->update(['key' => $key, 'vendor_id' => $value('vendor_id') ? (int) $value('vendor_id') : null, 'last_message_at' => $at ?? $c->created_at]);
                DB::table('mail_candidate_messages')->insertOrIgnore(['candidate_id' => $c->id, 'message_id' => $c->message_id, 'created_at' => $c->created_at]);
            }
            // Дубли ждущих одной ТС — в самого раннего: письма переезжают, поля дописываются, поздние пропадают.
            $open = DB::table('mail_candidates')->whereIn('state', ['new', 'rejected'])->orderBy('id')->get()->groupBy(fn ($c) => $c->scope.'|'.$c->key);
            foreach ($open as $group) {
                if ($group->count() < 2) {
                    continue;
                }
                $first = $group->first();
                $extracted = json_decode($first->extracted ?: '{}', true) ?: [];
                $proposed = null;
                foreach ($group->slice(1) as $dup) {
                    $fields = json_decode($dup->extracted ?: '{}', true) ?: [];
                    $extracted += $fields;
                    $proposed = $fields;
                    DB::table('mail_candidate_messages')->where('candidate_id', $dup->id)->update(['candidate_id' => $first->id]);
                    DB::table('mail_candidates')->where('id', $dup->id)->delete();
                }
                $ids = DB::table('mail_candidate_messages')->where('candidate_id', $first->id)->pluck('message_id');
                DB::table('mail_candidates')->where('id', $first->id)->update([
                    'extracted' => json_encode($extracted, JSON_UNESCAPED_UNICODE), 'proposed' => $proposed ? json_encode($proposed, JSON_UNESCAPED_UNICODE) : $first->proposed,
                    'messages_count' => $ids->count(), 'last_message_at' => DB::table('mail_messages')->whereIn('id', $ids)->max('date_at') ?? $first->created_at,
                    'vendor_id' => $first->vendor_id ?? ($extracted['vendor_id']['value'] ?? null),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_candidate_messages');
        Schema::table('mail_candidates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vendor_id');
            $t->dropColumn(['key', 'messages_count', 'last_message_at']);
        });
    }
};
