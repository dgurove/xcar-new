<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Оплата менеджером у всех вендоров — 14 дней, как у Совкомбанка (27.10.2026, владелец: «у остальных тоже поправь»):
 * «Счёт выставлен менеджеру» и «Оплата подбора» — 14 суток вместо 3. Идущие позиции — от того же старта (старый срок
 * минус 3 дня плюс 14), их открытые счета — тем же днём. Уже прошедший срок — просроченным и напомненным, без рассылки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stages = DB::table('workflow_stages')->whereIn('name', ['Счёт выставлен менеджеру', 'Оплата подбора'])->where('limit_minutes', 3 * 24 * 60)->pluck('id');
        DB::table('workflow_stages')->whereIn('id', $stages)->update(['limit_minutes' => 14 * 24 * 60, 'limit_workdays' => false]);
        $positions = DB::table('offer_positions as p')->join('deals as d', fn ($j) => $j->on('d.offer_id', '=', 'p.offer_id')->where('d.state', 'active'))
            ->whereIn('p.stage_id', $stages)->where('p.track', 'sale')->whereNotNull('p.deadline_at')
            ->get(['p.id', 'p.stage_id', 'p.deadline_at', 'd.id as deal_id']);
        foreach ($positions as $p) {
            $due = Carbon::parse($p->deadline_at)->addDays(11);
            $past = $due->isPast();
            DB::table('offer_positions')->where('id', $p->id)->update(['deadline_at' => $due,
                'reminded_at' => $past ? now() : null, 'overdue_at' => $past ? now() : null]);
            DB::table('requirements')->where('deal_id', $p->deal_id)->where('stage_id', $p->stage_id)->whereNull('done_at')->update(['due_at' => $due]);
            DB::table('billing_invoices')->where('deal_id', $p->deal_id)->where('direction', 'issued')->where('state', 'issued')
                ->where('kind', '<>', 'reward')->update(['due_at' => $due->toDateString(), 'overdue_at' => $past ? DB::raw('overdue_at') : null]);
        }
    }
};
