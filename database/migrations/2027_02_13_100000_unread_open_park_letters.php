<?php

use App\Mail\Jobs\PushFlag;
use App\Mail\Thread;
use App\Mail\Threads;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Разово (30.10.2026, управляющий парковкой): письма о приёме и выдаче, которые ещё не сделаны, снова непрочитанные — и у
 * нас, и в ящике storage@. Их гасило заведение заявки и ТС по письмам (`RegisterCandidate::attach` → `MarkThreadRead`), хотя
 * их никто не открывал; теперь «прочитано» — только у человека. Какие: входящие письма веток ТС с открытой заявкой на
 * приём (ТС ещё ждёт) или на выдачу (ТС ещё стоит), только ящики парковки. Повтор ничего не меняет — уже непрочитанные
 * не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vehicles = DB::table('park_requests as r')->join('park_vehicles as v', 'v.id', '=', 'r.vehicle_id')
            ->where('r.state', 'new')
            ->where(fn ($q) => $q->where(fn ($i) => $i->where('r.type', 'intake')->where('v.state', 'expected'))
                ->orWhere(fn ($o) => $o->where('r.type', 'release')->where('v.state', 'stored')))
            ->distinct()->pluck('r.vehicle_id');
        if ($vehicles->isEmpty()) {
            return;
        }
        $messages = DB::table('mail_messages as m')->join('mail_threads as t', 't.id', '=', 'm.thread_id')
            ->join('mail_accounts as a', 'a.id', '=', 'm.account_id')
            ->where('a.scope', 'park')->where('m.direction', 'in')->where('m.is_seen', true)
            ->whereIn('t.vehicle_id', $vehicles)
            ->get(['m.id', 'm.thread_id']);
        if ($messages->isEmpty()) {
            return;
        }
        DB::table('mail_messages')->whereIn('id', $messages->pluck('id'))->update(['is_seen' => false]);
        // В ящик — флаг снимается по одному письму (очередь `mail`), как у «Непрочитано» в окне ветки.
        foreach ($messages->pluck('id') as $id) {
            PushFlag::dispatch($id, '\\Seen', false);
        }
        // Счётчики непрочитанных у веток и в таб-баре.
        Thread::whereIn('id', $messages->pluck('thread_id')->unique())->get()->each(fn (Thread $t) => app(Threads::class)->refresh($t));
        logger()->info('Почта парковки: письма по незаконченным заявкам снова непрочитанные', ['messages' => $messages->count(), 'vehicles' => $vehicles->count()]);
    }

    public function down(): void
    {
        // Разовая правка флагов: назад не откатывается — что было прочитано на самом деле, уже не узнать.
    }
};
