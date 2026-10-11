<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Письмо поставщику — шаг (11.10.2026): идущие сделки на этапе с письмом (шаблон и ход поставщика) размечаются по
 * почте. Наше письмо в ветке предложения с ящика продаж после входа на этап есть — срок ответа от него (`letter_at`), а
 * если поставщик после него ответил, а исход не отмечен — ход наш (`reply_at`). Письма нет — ход наш, без срока
 * (Tenet T7, Camry, Logan 11.10.2026: окно закрыли, не отправив).
 */
return new class extends Migration
{
    public function up(): void
    {
        $offersAccounts = DB::table('mail_accounts')->where('scope', 'offers')->pluck('id');
        // Ответ поставщика — только на ящик, который пишет (deal@), как `InsurerReplies::for`.
        $dealAccounts = DB::table('mail_accounts')->where('scope', 'offers')->whereNull('reply_account_id')->pluck('id');
        $positions = DB::table('offer_positions as p')
            ->join('workflow_stages as s', 's.id', '=', 'p.stage_id')
            ->join('deals as d', fn ($j) => $j->on('d.offer_id', '=', 'p.offer_id')->where('d.state', 'active'))
            ->where('p.track', 'sale')->whereNotNull('s.template_id')->where('s.waits_for', 'supplier')
            ->select('p.id', 'p.offer_id', 'p.entered_at', 'p.payload', 's.limit_minutes', 's.deadline_source')
            ->get();

        foreach ($positions as $p) {
            $payload = json_decode($p->payload ?? 'null', true) ?: [];
            if (isset($payload['letter_at'])) {
                continue;
            }
            $threads = DB::table('mail_threads')->where('offer_id', $p->offer_id)->whereIn('account_id', $offersAccounts)->pluck('id');
            $letter = $threads->isEmpty() ? null : DB::table('mail_messages')->whereIn('thread_id', $threads)->where('direction', 'out')
                ->where(fn ($q) => $q->whereNull('send_state')->orWhere('send_state', 'sent'))
                ->whereRaw('coalesce(sent_at, date_at) >= ?', [$p->entered_at])
                ->orderByRaw('coalesce(sent_at, date_at)')->first(['id', 'sent_at', 'date_at']);

            if (! $letter) {
                DB::table('offer_positions')->where('id', $p->id)->update(['waits_for' => 'us', 'deadline_at' => null, 'overdue_at' => null, 'reminded_at' => null]);

                continue;
            }
            $at = Carbon::parse($letter->sent_at ?? $letter->date_at);
            $payload += ['letter_id' => $letter->id, 'letter_at' => $at->toIso8601String()];
            $update = ['payload' => json_encode($payload)];
            if ($p->deadline_source === 'own' && $p->limit_minutes) {
                $deadline = $at->copy()->addMinutes($p->limit_minutes);
                $update += ['deadline_at' => $deadline, 'overdue_at' => $deadline->isPast() ? now() : null, 'reminded_at' => null];
            }
            $reply = DB::table('mail_messages')->whereIn('thread_id', DB::table('mail_threads')->whereIn('id', $threads)->whereIn('account_id', $dealAccounts)->pluck('id'))->where('direction', 'in')
                ->where(fn ($q) => $q->whereNull('intent')->orWhere('intent', '!=', 'auto'))
                ->where('date_at', '>', $at)->orderBy('date_at')->first(['date_at']);
            if ($reply) {
                $payload['reply_at'] = Carbon::parse($reply->date_at)->toIso8601String();
                $update = ['payload' => json_encode($payload), 'waits_for' => 'us'] + $update;
            }
            DB::table('offer_positions')->where('id', $p->id)->update($update);
        }
    }

    public function down(): void {}
};
