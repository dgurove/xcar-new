<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Путь гаражной машины — из «Истории» машины (29.10.2026, владелец: одно событие — одно место). Этап писался дважды:
 * в `garage_cars.history` (json) и строкой `car_events` `garage` / `do: stage`; первый шаг «Ждёт машину» и записи до
 * 06.10.2026 жили только в json. Недостающие этапы дописываются в журнал тем же временем, json уходит.
 * Заодно уходят `garage_cars.cost` (закупочная машины, отданной руками без сделки до 06.10.2026: таких строк нет, закупочная —
 * у сделки) и `note` (не писал никто).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [];
        foreach (DB::table('garage_cars as g')->join('offers as o', 'o.id', '=', 'g.offer_id')->whereNotNull('g.history')->get(['g.offer_id', 'o.car_id', 'g.history', 'g.created_by']) as $g) {
            $logged = DB::table('car_events')->where('offer_id', $g->offer_id)->where('type', 'garage')->whereRaw("payload->>'do' = 'stage'")
                ->get(['payload', 'created_at'])->map(fn ($e) => [json_decode($e->payload, true)['state'] ?? null, Carbon::parse($e->created_at)]);
            foreach (json_decode($g->history, true) ?: [] as $i => [$state, $at]) {
                $at = Carbon::parse($at)->setTimezone(config('app.timezone'));
                if ($logged->contains(fn ($e) => $e[0] === $state && abs($e[1]->diffInSeconds($at)) <= 5)) {
                    continue;
                }
                $rows[] = ['car_id' => $g->car_id, 'offer_id' => $g->offer_id, 'user_id' => $i === 0 ? $g->created_by : null, 'type' => 'garage',
                    'payload' => json_encode(['do' => 'stage', 'state' => $state]), 'created_at' => $at->format('Y-m-d H:i:s')];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('car_events')->insert($chunk);
        }

        Schema::table('garage_cars', function (Blueprint $t) {
            $t->dropColumn(['history', 'cost', 'note']);
        });
    }

    public function down(): void
    {
        Schema::table('garage_cars', function (Blueprint $t) {
            $t->jsonb('history')->nullable();
            $t->integer('cost')->nullable();
            $t->text('note')->nullable();
        });
    }
};
