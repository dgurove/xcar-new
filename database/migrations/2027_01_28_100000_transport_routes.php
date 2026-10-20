<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Маршрут вывоза из отрезков (20.10.2026, владелец: «стоянка в регионе, потом межрегион до стоянки в другом регионе, потом
 * довоз — и у каждого отрезка свой исполнитель»): отрезок — строка `transports`, маршрут — отрезки с одним `route_id` (id
 * первого) по `seq`; ровно один `current` — тот, что сейчас идёт (у законченного — последний). Неначатый отрезок —
 * состояние `planned`. Цель после стоянки больше не держится в `next_*`: она у последнего отрезка — у висящей задачи на
 * стоянку заводится запланированный следующий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->unsignedBigInteger('route_id')->nullable()->after('previous_id');
            $t->unsignedSmallInteger('seq')->default(1)->after('route_id');
            $t->boolean('current')->default(true)->after('seq');
            $t->index(['route_id', 'seq']);
            $t->index('current');
        });

        // Цепочки `previous_id` → маршрут: id первого и номер звена.
        DB::statement(<<<'SQL'
            with recursive chain as (
                select id, id as route_id, 1 as seq from transports where previous_id is null
                union all
                select t.id, c.route_id, c.seq + 1 from transports t join chain c on t.previous_id = c.id
            )
            update transports set route_id = chain.route_id, seq = chain.seq from chain where chain.id = transports.id
            SQL);
        DB::table('transports')->whereNull('route_id')->update(['route_id' => DB::raw('id')]);

        // Висящая задача на стоянку (ещё не привезли) — её цель после стоянки становится запланированным следующим отрезком.
        $now = now();
        foreach (DB::table('transports')->where('destination', 'depot')->whereNotIn('state', ['delivered', 'cancelled'])
            ->whereNotExists(fn ($q) => $q->from('transports as n')->whereColumn('n.previous_id', 'transports.id'))->get() as $t) {
            $depot = DB::table('logistics_depots')->find($t->depot_id);
            DB::table('transports')->insert([
                'car_id' => $t->car_id, 'origin' => $t->origin, 'kind' => 'move', 'channel' => 'logist', 'logist_id' => $t->logist_id,
                'state' => 'planned', 'previous_id' => $t->id, 'route_id' => $t->route_id, 'seq' => $t->seq + 1, 'current' => false,
                'from_depot_id' => $t->depot_id, 'from_address' => $depot ? trim('Стоянка '.$depot->name) : null,
                'from_contact' => $depot?->contact_name, 'from_phone' => $depot?->phone,
                'destination' => $t->next_destination, 'to_yard_id' => $t->next_to_yard_id, 'to_address' => $t->next_to_address,
                'reach' => $t->reach, 'client_id' => $t->client_id, 'region_id' => $t->region_id, 'created_by' => $t->created_by,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Текущий отрезок: идущий (не запланирован и не закрыт); маршрут кончился — последний.
        DB::statement(<<<'SQL'
            update transports t set current = (
                case
                    when exists (select 1 from transports o where o.route_id = t.route_id and o.state in ('new', 'logist', 'assigned', 'agreed', 'moving'))
                        then t.state in ('new', 'logist', 'assigned', 'agreed', 'moving')
                    else not exists (select 1 from transports l where l.route_id = t.route_id and l.seq > t.seq and l.state <> 'planned')
                        and t.state <> 'planned'
                end)
            SQL);

        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('next_to_yard_id');
            $t->dropColumn(['next_destination', 'next_to_address']);
        });
    }

    public function down(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->string('next_destination', 12)->nullable();
            $t->foreignId('next_to_yard_id')->nullable()->constrained('park_yards')->nullOnDelete();
            $t->string('next_to_address', 500)->nullable();
        });
        DB::table('transports')->where('state', 'planned')->delete();
        Schema::table('transports', function (Blueprint $t) {
            $t->dropIndex(['route_id', 'seq']);
            $t->dropIndex(['current']);
            $t->dropColumn(['route_id', 'seq', 'current']);
        });
    }
};
