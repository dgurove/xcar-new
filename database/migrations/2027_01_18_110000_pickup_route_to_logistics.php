<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Маршрут «Вывоз» CRM (Track::Service) → задачи логистики (17.10.2026). На проде: 177 машин на «Связь со
 * страхователем» (Совкомбанк запускал маршрут с каждым письмом, двигать его было некому), 7 на «Вывоз и осмотр», 8 на
 * «Стоит у менеджера». Живая машина получает задачу:
 * — уже у менеджера или у нас (этап «Стоит у …», закрытая сделка, продажа прошла «Автомобиль забрал / передан») — «Привёз»;
 * — вывоз поручен менеджеру (`evacuator_id`) — назначена ему, документы без СДЭКа (он вёл её до этого сам);
 * — иначе — «Без исполнителя», выбирает админ.
 * Архив, отмена и выданные — без задачи. Дальше маршруты вывоза, их позиции, `evacuator_id` и `evacuation_to` уходят.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('offer_positions as p')
            ->join('workflow_stages as s', 's.id', '=', 'p.stage_id')
            ->join('offers as o', 'o.id', '=', 'p.offer_id')
            ->where('p.track', 'service')
            ->whereNotIn('o.state', ['delivered', 'cancelled', 'archived'])
            ->get(['o.id as offer_id', 'o.car_id', 'o.evacuator_id', 'o.evacuation_to', 's.car_place', 'p.entered_at']);

        foreach ($rows as $r) {
            $done = in_array($r->car_place, ['keeper', 'with_us'], true)
                || DB::table('deals')->where('offer_id', $r->offer_id)->where('state', 'done')->exists()
                || DB::table('offer_events')->where('offer_id', $r->offer_id)->where('type', 'stage_entered')
                    ->whereRaw("payload->>'track' = 'sale'")->whereRaw("payload->>'exit' in ('Автомобиль забрал', 'Автомобиль передан')")->exists();
            $manager = $r->evacuator_id;
            $at = $r->entered_at ?? now();
            $data = [
                'kind' => 'pickup',
                'executor' => $manager ? 'manager' : null,
                'executor_id' => $manager,
                'destination' => $r->evacuation_to === 'keeper' || ($manager && $r->evacuation_to === null) ? 'keeper' : ($done ? 'ours' : null),
                'docs_by_cdek' => false,
                'state' => $done ? 'delivered' : ($manager ? 'assigned' : 'new'),
                'assigned_at' => $manager ? $at : null,
                'picked_at' => $done ? $at : null,
                'delivered_at' => $done ? $at : null,
                'updated_at' => now(),
            ];
            $open = DB::table('transports')->where('car_id', $r->car_id)->where('origin', 'sale')
                ->whereIn('state', ['new', 'logist', 'assigned', 'agreed', 'moving'])->orderByDesc('id')->first();
            if ($open) {
                DB::table('transports')->where('id', $open->id)->update($data);
            } else {
                DB::table('transports')->insert($data + ['car_id' => $r->car_id, 'origin' => 'sale', 'created_at' => $at]);
            }
            if (! $done) {
                DB::table('offers')->where('id', $r->offer_id)->whereNull('car_place')->update(['car_place' => 'owner']);
            }
        }

        // Маршрута вывоза больше нет: позиции (их этапы держат restrict), затем сами маршруты со своими этапами.
        DB::table('offer_positions')->where('track', 'service')->delete();
        DB::table('workflows')->where('track', 'service')->delete();

        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('evacuator_id');
            $t->dropColumn('evacuation_to');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('evacuator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('evacuation_to', 8)->nullable();
        });
    }
};
