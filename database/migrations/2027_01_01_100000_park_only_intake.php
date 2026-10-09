<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Парковка — только приём (владелец 10.10.2026: «назначение даты приёма не нужно нигде, вырезай, как и эвакуацию»).
 * Заявок «Эвакуация», состояний «назначена» и «в работе», ТС «в пути», звонка и даты приезда в коде больше нет:
 * - эвакуация к ожидаемой ТС — обычный приём; перегон стоящей (или второй приём рядом с открытым) — отменён;
 * - ТС в пути: принятая раньше (перегон) — снова на парковке, откуда уехала; не принятая — снова ожидается;
 * - у открытых приёмов стираются срок, доставка и звонок: иначе часы прислали бы «Срок вышел» по заявке без срока.
 * Колонки остаются — закрытые заявки хранят то, что было.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (DB::table('park_vehicles')->where('state', 'in_transit')->get(['id', 'accepted_at', 'yard_id']) as $v) {
            $departed = DB::table('park_vehicle_events')->where('vehicle_id', $v->id)->where('type', 'departed')->orderByDesc('id')->value('payload');
            $from = json_decode((string) $departed, true)['from_id'] ?? null;
            DB::table('park_vehicles')->where('id', $v->id)->update($v->accepted_at && $from
                ? ['state' => 'stored', 'yard_id' => $from, 'transit_started_at' => null, 'updated_at' => $now]
                : ['state' => 'expected', 'transit_started_at' => null, 'updated_at' => $now]);
        }

        $open = ['new', 'scheduled', 'in_progress'];
        foreach (DB::table('park_requests')->where('type', 'tow')->get(['id', 'vehicle_id', 'state']) as $r) {
            $state = DB::table('park_vehicles')->where('id', $r->vehicle_id)->value('state');
            $twin = DB::table('park_requests')->where('vehicle_id', $r->vehicle_id)->where('type', 'intake')->whereIn('state', $open)->exists();
            $live = in_array($r->state, $open, true);
            DB::table('park_requests')->where('id', $r->id)->update(match (true) {
                ! $live => ['type' => 'intake'],
                $state === 'expected' && ! $twin => ['type' => 'intake', 'state' => 'new'],
                default => ['type' => 'intake', 'state' => 'cancelled', 'done_at' => $now, 'cancel_reason' => 'Эвакуации больше нет'],
            } + ['updated_at' => $now]);
        }
        // Выдача и прочие с этими состояниями — просто открытые.
        DB::table('park_requests')->whereIn('state', ['scheduled', 'in_progress'])->update(['state' => 'new', 'updated_at' => $now]);

        DB::table('park_requests')->where('type', 'intake')->where('state', 'new')->update([
            'planned_at' => null, 'delivery' => null, 'contacted_at' => null, 'next_call_at' => null, 'reminded_at' => null, 'overdue_at' => null,
        ]);
    }

    public function down(): void {}
};
