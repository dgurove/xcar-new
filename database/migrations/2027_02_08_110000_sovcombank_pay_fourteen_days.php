<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Совкомбанк: на оплату счёта ПРАЙМ — 14 дней, а не 5 (27.10.2026, владелец: «5 дней — как-то слишком мало, обычно 14
 * делают»). Живые позиции на этапе — от своего входа (гаражная — от «Продаю», не продана — без часов, `Deal::payDue`),
 * открытые счета этих сделок — тем же днём. Срок, что и теперь прошёл, отмечен просроченным и напомненным: часы не
 * разошлют пачку «срок вышел»; сдвинутый в будущее — снова живой.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stages = DB::table('workflow_stages')->where('name', 'Оплата по счёту ПРАЙМ')->pluck('id');
        DB::table('workflow_stages')->whereIn('id', $stages)->update(['limit_minutes' => 14 * 24 * 60, 'limit_workdays' => false]);
        $positions = DB::table('offer_positions as p')->join('deals as d', fn ($j) => $j->on('d.offer_id', '=', 'p.offer_id')->where('d.state', 'active'))
            ->leftJoin('garage_cars as g', 'g.deal_id', '=', 'd.id')
            ->whereIn('p.stage_id', $stages)->where('p.track', 'sale')
            ->get(['p.id', 'p.stage_id', 'p.entered_at', 'd.id as deal_id', 'd.garage_payer', 'g.sold_at']);
        foreach ($positions as $p) {
            $from = Carbon::parse($p->entered_at);
            if ($p->garage_payer !== null) {
                $from = $p->sold_at ? Carbon::parse($p->sold_at)->max($from) : null;
            }
            $due = $from?->addDays(14);
            $past = $due?->isPast();
            DB::table('offer_positions')->where('id', $p->id)->update(['deadline_at' => $due,
                'reminded_at' => $past ? now() : null, 'overdue_at' => $past ? now() : null]);
            DB::table('requirements')->where('deal_id', $p->deal_id)->where('stage_id', $p->stage_id)->whereNull('done_at')->update(['due_at' => $due]);
            if ($due) {
                DB::table('billing_invoices')->where('deal_id', $p->deal_id)->where('direction', 'issued')->where('state', 'issued')
                    ->where('kind', '<>', 'reward')->update(['due_at' => $due->toDateString(), 'overdue_at' => $past ? DB::raw('overdue_at') : null]);
            }
        }
    }
};
