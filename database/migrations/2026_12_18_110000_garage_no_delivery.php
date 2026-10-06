<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * В гараже нет «Доставки» (06.10.2026): где машина — это вывоз. Стоявшие на ней машины — на «Подготовку», если вывоз
 * уже довёз (у менеджера, у нас), иначе «Ждёт машину». Из пути машины этап уходит. Модель тут не годится: её этап —
 * перечисление, и `delivery` в нём больше нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('garage_cars')->where('state', 'delivery')->get() as $car) {
            $arrived = DB::table('offer_positions')->join('workflow_stages', 'workflow_stages.id', '=', 'offer_positions.stage_id')
                ->where('offer_positions.offer_id', $car->offer_id)->where('offer_positions.track', 'service')
                ->whereIn('workflow_stages.car_place', ['keeper', 'with_us'])->exists();
            DB::table('garage_cars')->where('id', $car->id)->update(['state' => $arrived ? 'repair' : 'waiting']);
        }
        foreach (DB::table('garage_cars')->where('history', 'like', '%delivery%')->get(['id', 'history', 'state', 'stage_at']) as $car) {
            $history = array_values(array_filter(json_decode($car->history, true) ?? [], fn ($h) => $h[0] !== 'delivery'));
            if (! $history || end($history)[0] !== $car->state) {
                $history[] = [$car->state, Carbon::parse($car->stage_at)->toIso8601String()];
            }
            DB::table('garage_cars')->where('id', $car->id)->update(['history' => json_encode($history)]);
        }
    }

    public function down(): void {}
};
