<?php

namespace App\Mail\Console;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\MarkMessageRead;
use App\Mail\Actions\ReadEarlier;
use App\Mail\Actions\ReadSettled;
use App\Mail\Message;
use App\Mail\Thread;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Прошлое правила «прочитано по ходу машины» (`ReadSettled`, 23.11.2026): непрочитанные входящие живых веток, с которыми по
 * ходу машины уже работали. Моменты — из базы: переход в нынешнее состояние (`car_events` `state_changed`, иначе начало
 * сделки), заведение черновика человеком из цепочки, приём, выдача и отмена ТС; плюс `ReadEarlier` (позже в ветке есть
 * письмо) и рассылки парковки в архив; по ходу сделки — закрытый «Разобрать ответ», решённая переоценка, шаг сотрудником, машину
 * забрали, сотрудник написал поставщику после письма. Без `--apply` — счёт по причинам и что остаётся непрочитанным.
 */
class ReadSettledCommand extends Command
{
    protected $signature = 'mail:read-settled {--apply : пометить прочитанными}';

    protected $description = 'Прочитать письма машин, что ушли дальше (сделка, выдача, приём на парковку)';

    public function handle(MarkMessageRead $mark, ReadEarlier $earlier, ArchiveThread $archive): int
    {
        $rows = collect(DB::select(<<<'SQL'
            select m.id, m.thread_id, m.date_at, m.intent, m.subject, a.email box, a.scope,
              t.offer_id, t.vehicle_id, t.candidate_id, coalesce(t.offer_id, c.offer_id) oid,
              o.state ostate, o.number, o.car_id, o.created_at ocreated,
              v.state vstate, (select cc.claim_ref from cars cc where cc.id = v.car_id) vref, v.accepted_at, v.released_at, v.cancelled_at,
              exists(select 1 from mail_messages x where x.thread_id = m.thread_id and x.id <> m.id
                and (x.date_at > m.date_at or (x.date_at = m.date_at and x.id > m.id))) later,
              exists(select 1 from mail_messages x join mail_threads tx on tx.id = x.thread_id join mail_accounts ax on ax.id = x.account_id
                where ax.scope = a.scope and (case when ax.scope = 'park' then coalesce('v:' || tx.vehicle_id, 'c:' || tx.candidate_id, 't:' || tx.id) else coalesce('o:' || tx.offer_id, 'c:' || tx.candidate_id, 't:' || tx.id) end) = (case when a.scope = 'park' then coalesce('v:' || t.vehicle_id, 'c:' || t.candidate_id, 't:' || t.id) else coalesce('o:' || t.offer_id, 'c:' || t.candidate_id, 't:' || t.id) end) and x.date_at > m.date_at
                and (x.is_seen or x.direction = 'out')) chain_later,
              exists(select 1 from reply_reviews r join mail_messages rm on rm.id = r.message_id where r.state = 'done'
                and rm.thread_id = m.thread_id and rm.date_at >= m.date_at) reviewed,
              exists(select 1 from offer_reprices rp where rp.message_id = m.id and rp.state in ('applied', 'dismissed')) repriced,
              exists(select 1 from car_events e join users u on u.id = e.user_id where e.car_id = o.car_id and e.type = 'stage_entered'
                and e.created_at > m.date_at and e.payload->>'exit' is not null and e.payload->>'letter' is null
                and coalesce(e.payload->>'label', '') not like '%по письму поставщика%' and jsonb_exists_any(u.roles, array['admin', 'moderator'])) staff_step,
              exists(select 1 from car_events e where e.car_id = o.car_id and e.type = 'place_changed' and e.created_at > m.date_at
                and coalesce(e.payload->>'place', 'owner') <> 'owner'
                and coalesce((select e0.payload->>'place' from car_events e0 where e0.car_id = e.car_id and e0.type = 'place_changed'
                  and e0.created_at < e.created_at order by e0.created_at desc limit 1), 'owner') = 'owner') picked,
              exists(select 1 from mail_messages x join mail_threads tx on tx.id = x.thread_id join users u on u.id = x.created_by
                where tx.offer_id = o.id and x.direction = 'out' and x.date_at > m.date_at and jsonb_exists_any(u.roles, array['admin', 'moderator'])) ours_after
            from mail_messages m
            join mail_threads t on t.id = m.thread_id
            join mail_accounts a on a.id = m.account_id
            left join mail_candidates c on c.id = t.candidate_id
            left join offers o on o.id = coalesce(t.offer_id, c.offer_id)
            left join park_vehicles v on v.id = t.vehicle_id
            where t.archived_at is null and m.direction = 'in' and not m.is_seen
            order by m.date_at
            SQL));
        $cars = $rows->pluck('car_id')->filter()->unique()->values()->all();
        // Последний переход в нынешнее состояние машины; без записи — начало сделки.
        $moved = collect(DB::table('car_events')->whereIn('car_id', $cars)->where('type', 'state_changed')
            ->selectRaw("car_id, payload->>'to' as st, max(created_at) as at")->groupBy('car_id', 'st')->get())
            ->keyBy(fn ($r) => $r->car_id.':'.$r->st);
        $dealStart = DB::table('deals')->whereIn('offer_id', $rows->pluck('oid')->filter()->unique()->all())
            ->whereIn('state', ['active', 'done'])->groupBy('offer_id')->selectRaw('offer_id, max(created_at) as at')->pluck('at', 'offer_id');
        $byHand = DB::table('car_events')->whereIn('car_id', $cars)->where('type', 'created')->whereNotNull('user_id')->pluck('car_id')->flip();
        $settled = array_map(fn ($s) => $s->value, ReadSettled::STATES);

        $read = [];
        $mailings = [];
        $left = [];
        foreach ($rows as $r) {
            $reason = match (true) {
                (bool) $r->chain_later => 'позже в цепочке прочитанное или наше',
                (bool) $r->later => 'позже в ветке есть письмо',
                $r->ostate && in_array($r->ostate, $settled, true) && $r->date_at <= ($moved[$r->car_id.':'.$r->ostate]->at ?? $dealStart[$r->oid] ?? '') => 'до перехода: '.$r->ostate,
                $r->candidate_id && $r->oid && isset($byHand[$r->car_id]) && $r->date_at <= $r->ocreated => 'черновик завели из цепочки',
                $r->vehicle_id && in_array($r->vstate, ['released', 'cancelled'], true) && $r->date_at <= ($r->released_at ?? $r->cancelled_at) => 'ТС '.$r->vstate,
                $r->vehicle_id && $r->accepted_at && $r->intent === 'intake' && $r->date_at <= $r->accepted_at => 'заявка, ТС принято',
                (bool) $r->reviewed => '«Разобрать ответ» закрыт',
                (bool) $r->repriced => 'переоценку решили',
                (bool) $r->staff_step => 'шаг сдвинул сотрудник',
                (bool) $r->picked => 'машину забрали',
                (bool) $r->ours_after => 'сотрудник написал поставщику',
                default => null,
            };
            if ($reason) {
                $read[$reason][] = $r;
            } elseif ($r->scope === 'park' && ! $r->vehicle_id && ! $r->candidate_id && $r->intent === 'auto') {
                $mailings[$r->thread_id] = $r;
            } else {
                $left[] = $r;
            }
        }

        $this->line('Непрочитанных входящих: '.$rows->count());
        foreach ($read as $reason => $list) {
            $this->line('  прочитать, '.$reason.': '.count($list));
        }
        $this->line('  рассылки парковки в архив: '.count($mailings));
        $this->line('Остаётся непрочитанным: '.count($left));
        foreach ($left as $r) {
            $what = $r->number ? '№'.$r->number.' '.$r->ostate : ($r->vref ? 'ТС '.$r->vref.' '.$r->vstate : 'без машины');
            $this->line('  '.substr($r->date_at, 0, 10).' '.$r->box.' '.$what.' — '.mb_substr((string) $r->subject, 0, 70));
        }
        if (! $this->option('apply')) {
            $this->line('Ничего не трогал (--apply — сделать)');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($read as $reason => $list) {
            if ($reason === 'позже в ветке есть письмо') {
                foreach (collect($list)->pluck('thread_id')->unique() as $id) {
                    $last = Message::where('thread_id', $id)->whereNotNull('date_at')->orderByDesc('date_at')->orderByDesc('id')->first();
                    $done += $last ? count($earlier($last)) : 0;
                }
            } else {
                $done += count($mark->apply(collect($list)->pluck('id')->all(), true));
            }
        }
        foreach (Thread::whereIn('id', array_keys($mailings))->get() as $thread) {
            $archive($thread);
        }
        $this->line("Прочитано: {$done}, в архив веток: ".count($mailings));

        return self::SUCCESS;
    }
}
