<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Совкомбанк: 5 дней на оплату счёта ПРАЙМ от «Забрал», указал менеджер покупателя или нет (27.10.2026, владелец: «я всё
 * ещё не понимаю, кто сколько должен и когда»). Было 3 дня, а без счёта шаг брал срок обязывающего — у старых сделок март.
 * Живые позиции на этапе получают срок от своего входа; гаражная без цены продажи — без часов (продаёт сама). Уже
 * прошедший срок отмечен просроченным и напомненным: часы не разошлют пачку «срок вышел».
 */
return new class extends Migration
{
    public function up(): void
    {
        $stages = DB::table('workflow_stages')->where('name', 'Оплата по счёту ПРАЙМ')->pluck('id');
        DB::table('workflow_stages')->whereIn('id', $stages)->update(['limit_minutes' => 5 * 24 * 60, 'limit_workdays' => false]);
        $positions = DB::table('offer_positions as p')->join('deals as d', fn ($j) => $j->on('d.offer_id', '=', 'p.offer_id')->where('d.state', 'active'))
            ->whereIn('p.stage_id', $stages)->where('p.track', 'sale')
            ->get(['p.id', 'p.offer_id', 'p.stage_id', 'p.entered_at', 'd.id as deal_id', 'd.garage_payer', 'd.amount']);
        foreach ($positions as $p) {
            $due = $p->garage_payer !== null && $p->amount === null ? null : Carbon::parse($p->entered_at)->addDays(5);
            $past = $due?->isPast();
            DB::table('offer_positions')->where('id', $p->id)->update(['deadline_at' => $due,
                'reminded_at' => $past ? now() : null, 'overdue_at' => $past ? now() : null]);
            DB::table('requirements')->where('deal_id', $p->deal_id)->where('stage_id', $p->stage_id)->whereNull('done_at')->update(['due_at' => $due]);
            if ($due) {
                DB::table('billing_invoices')->where('deal_id', $p->deal_id)->where('direction', 'issued')->where('state', 'issued')
                    ->where('kind', '<>', 'reward')->update(['due_at' => $due->toDateString()]);
            }
        }
    }
};
