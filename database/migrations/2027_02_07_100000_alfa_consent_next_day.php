<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Согласие менеджера (Альфа) — до 17:00 следующего дня вместо 4 часов, а письмо поставщику после «Беру в работу» —
 * «Подтверждаем актуальность» (27.10.2026, владелец: «сначала у менеджера спросить точно ли он берёт — дальше отказаться
 * нельзя; даём сегодня и до 17:00 завтра»). Живые позиции на этапе получают новый срок от своего входа.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stages = DB::table('workflow_stages')->where('name', 'Согласие менеджера')->pluck('id');
        DB::table('workflow_stages')->whereIn('id', $stages)->update(['deadline_source' => 'next_day_17', 'limit_minutes' => null, 'limit_workdays' => false]);
        foreach (DB::table('offer_positions')->whereIn('stage_id', $stages)->whereNull('waits_for')->get(['id', 'entered_at']) as $p) {
            $due = Carbon::parse($p->entered_at)->setTimezone('Europe/Moscow')->addDay()->setTime(17, 0)->setTimezone(config('app.timezone'));
            DB::table('offer_positions')->where('id', $p->id)->update(['deadline_at' => $due, 'reminded_at' => null,
                'overdue_at' => $due->isPast() ? DB::raw('overdue_at') : null]);
        }
        DB::table('mail_templates')->where('name', 'Поставщику: подтверждение покупки')->update([
            'subject' => '{{ claim_ref }}',
            'body' => "Здравствуйте!\n\nПодтверждаем актуальность: покупаем {{ car }}, VIN {{ vin }} ({{ claim_ref }}) за {{ floor }}\n\nС уважением,\nАндрей Кузнецов",
        ]);
    }
};
