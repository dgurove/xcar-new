<?php

use App\Mail\Extraction\Code;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Машина — одна запись (14.10.2026, владелец: «машина должна быть единой сущностью без дубликатов и костылей — и в
 * xcar, и в CRM, и в park, и в logistics»). Всё про саму машину переезжает из предложений, ТС парковки и лотов закупки в
 * `cars`; у дорожек остаётся `car_id`. Записи одной машины находятся так же, как их связывало приложение: ТС с
 * предложением (`park_vehicles.offer_id`), лот закупки с предложением (`purchase_cars.offer_id`), один номер убытка или
 * ДЛ, один VIN, если номера не спорят и продажа и хранение у машины остаются по одному. Поле берётся у парковки, потом у
 * продажи, потом у свежего лота; где непустые значения расходятся — строкой в `storage/logs/cars-backfill.log`. Файлы
 * предложений, ТС и лотов становятся файлами машины (путь у spatie — по номеру файла, на диске ничего не двигается).
 */
return new class extends Migration
{
    /** Колонка машины => [колонка у ТС, у предложения, у лота]; null — у дорожки такой нет. */
    private const SOURCES = [
        'claim_ref' => ['ref', 'claim_ref', 'dl'], 'vin' => ['vin', 'vin', 'vin'], 'plate' => ['plate', null, null],
        'brand_id' => ['brand_id', 'brand_id', 'brand_id'], 'model_id' => ['model_id', 'model_id', 'model_id'], 'year' => ['year', 'year', 'year'],
        'color' => ['color', 'color', 'color'], 'mileage' => ['mileage', 'mileage', 'mileage'], 'body' => [null, 'body', null],
        'transmission' => [null, 'transmission', 'transmission'], 'drive' => [null, 'drive', null], 'fuel' => [null, 'fuel', 'fuel'],
        'engine_volume' => [null, 'engine_volume', 'engine_volume'], 'engine_power' => [null, 'engine_power', 'engine_power'],
        'category' => ['category', 'vehicle_category', null], 'oversize' => ['oversize', null, null], 'damage_cause' => [null, 'damage_cause', null],
        'damage_zones' => ['damage_zones', 'damage_zones', null], 'damage_note' => ['damage_note', null, null], 'incident_date' => [null, 'incident_date', null],
        'is_runnable' => [null, 'is_runnable', null], 'has_keys' => [null, 'has_keys', null], 'papers' => [null, 'papers', null],
        'pts' => ['pts', null, null], 'sts' => ['sts', null, null], 'policy_no' => ['policy_no', null, null], 'value' => ['value', 'value', null],
        'insured_name' => ['contact_name', 'insured_name', null], 'insured_phone' => ['contact_phone', 'insured_phone', null],
        'flags' => ['flags', 'flags', null], 'docs_required' => ['docs_required', 'docs_required', null], 'holder' => [null, 'holder', null],
    ];

    private const DROP = [
        'offers' => ['brand_id', 'model_id', 'year', 'mileage', 'vin', 'body', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'color',
            'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers', 'incident_date', 'claim_ref', 'claim_ref_key', 'insured_name', 'insured_phone',
            'flags', 'holder', 'docs_required', 'vehicle_category', 'value'],
        'park_vehicles' => ['ref', 'ref_key', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'damage_zones', 'damage_note', 'offer_id', 'category',
            'oversize', 'contact_name', 'contact_phone', 'flags', 'docs_required', 'value', 'mileage', 'pts', 'sts', 'policy_no'],
        'purchase_cars' => ['dl', 'brand_id', 'model_id', 'year', 'vin', 'mileage', 'transmission', 'fuel', 'engine_volume', 'engine_power', 'color', 'offer_id'],
        'transports' => ['offer_id', 'vehicle_id'],
    ];

    private const CLOSED_OFFER = ['delivered', 'cancelled', 'archived'];

    private const CLOSED_VEHICLE = ['released', 'cancelled'];

    public function up(): void
    {
        Schema::create('cars', function (Blueprint $t) {
            $t->id();
            $t->string('claim_ref', 60)->nullable();
            $t->string('claim_ref_key', 60)->nullable();
            $t->string('vin', 17)->nullable()->index();
            $t->string('plate', 12)->nullable()->index();
            $t->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $t->foreignId('model_id')->nullable()->constrained('car_models')->nullOnDelete();
            $t->smallInteger('year')->nullable();
            $t->string('color', 32)->nullable();
            $t->integer('mileage')->nullable();
            $t->string('body', 24)->nullable();
            $t->string('transmission', 16)->nullable();
            $t->string('drive', 8)->nullable();
            $t->string('fuel', 16)->nullable();
            $t->smallInteger('engine_volume')->nullable();
            $t->smallInteger('engine_power')->nullable();
            $t->string('category', 12)->nullable()->index();
            $t->boolean('oversize')->default(false);
            $t->string('damage_cause', 24)->nullable();
            $t->jsonb('damage_zones')->default(DB::raw("'[]'::jsonb"));
            $t->text('damage_note')->nullable();
            $t->date('incident_date')->nullable();
            $t->boolean('is_runnable')->nullable();
            $t->boolean('has_keys')->nullable();
            $t->string('papers', 16)->nullable();
            $t->string('pts', 40)->nullable();
            $t->string('sts', 40)->nullable();
            $t->string('policy_no', 60)->nullable();
            $t->integer('value')->nullable();
            $t->string('insured_name', 80)->nullable();
            $t->string('insured_phone', 20)->nullable();
            $t->jsonb('flags')->default(DB::raw("'[]'::jsonb"));
            $t->jsonb('docs_required')->default(DB::raw("'[]'::jsonb"));
            $t->string('holder', 80)->nullable();
            $t->boolean('active')->default(false);
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
        });
        foreach (['offers', 'park_vehicles', 'purchase_cars', 'transports'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('car_id')->nullable()->constrained('cars')->restrictOnDelete());
        }

        $this->backfill();

        DB::statement('create unique index cars_claim_ref_key_unique on cars (claim_ref_key) where claim_ref_key is not null and not is_demo');
        DB::statement('create unique index cars_vin_active_unique on cars (vin) where active and length(vin) = 17');
        DB::statement('drop index if exists offers_claim_ref_key_unique');
        DB::statement('drop index if exists offers_vin_live_unique');
        DB::statement('drop index if exists park_vehicles_ref_key_unique');
        DB::statement('drop index if exists park_vehicles_vin_live_unique');
        Schema::table('purchase_cars', fn (Blueprint $t) => $t->dropUnique('purchase_cars_purchase_id_dl_unique'));
        foreach (self::DROP as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($columns));
        }
        foreach (['offers', 'park_vehicles', 'purchase_cars', 'transports'] as $table) {
            DB::statement("alter table {$table} alter column car_id set not null");
        }
        // Продажа и хранение у машины — по одной; лот закупки в одной закупке — один на машину.
        DB::statement('create unique index offers_car_id_unique on offers (car_id)');
        DB::statement('create unique index park_vehicles_car_id_unique on park_vehicles (car_id)');
        DB::statement('create unique index purchase_cars_purchase_id_car_id_unique on purchase_cars (purchase_id, car_id)');
        DB::statement('create index transports_car_id_index on transports (car_id)');
    }

    private function backfill(): void
    {
        $vehicles = DB::table('park_vehicles')->orderBy('id')->get()->keyBy('id');
        $offers = DB::table('offers')->orderBy('id')->get()->keyBy('id');
        $lots = DB::table('purchase_cars')->orderByDesc('id')->get()->keyBy('id');

        // Узлы: «v12», «o34», «p56». Компонента знает свои продажи, хранения и номера: VIN склеивает только то, что не спорит.
        $parent = [];
        $meta = [];
        $find = function (string $x) use (&$parent, &$find): string {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $node = function (string $x, string $kind, ?string $key) use (&$parent, &$meta) {
            $parent[$x] = $x;
            $meta[$x] = ['o' => $kind === 'o' ? 1 : 0, 'v' => $kind === 'v' ? 1 : 0, 'keys' => $key ? [$key => true] : [], 'demo' => false];
        };
        $fits = function (string $a, string $b) use (&$meta, $find): bool {
            [$a, $b] = [$find($a), $find($b)];

            return $a === $b || (! $meta[$a]['demo'] && ! $meta[$b]['demo'] && $meta[$a]['o'] + $meta[$b]['o'] <= 1 && $meta[$a]['v'] + $meta[$b]['v'] <= 1
                && count($meta[$a]['keys'] + $meta[$b]['keys']) <= 1);
        };
        $union = function (string $a, string $b) use (&$parent, &$meta, $find) {
            [$a, $b] = [$find($a), $find($b)];
            if ($a === $b) {
                return;
            }
            $parent[$b] = $a;
            $meta[$a] = ['o' => $meta[$a]['o'] + $meta[$b]['o'], 'v' => $meta[$a]['v'] + $meta[$b]['v'], 'keys' => $meta[$a]['keys'] + $meta[$b]['keys'], 'demo' => $meta[$a]['demo'] || $meta[$b]['demo']];
        };

        foreach ($vehicles as $v) {
            $node("v{$v->id}", 'v', $v->ref_key);
        }
        foreach ($offers as $o) {
            $node("o{$o->id}", 'o', $o->is_demo ? null : $o->claim_ref_key);
            $meta["o{$o->id}"]['demo'] = (bool) $o->is_demo;
        }
        foreach ($lots as $p) {
            $node("p{$p->id}", 'p', Code::key($p->dl));
        }
        $report = [];
        // Связи, что держало приложение: ТС ↔ предложение, лот ↔ предложение.
        foreach ($vehicles as $v) {
            if ($v->offer_id && isset($offers[$v->offer_id])) {
                $fits("v{$v->id}", "o{$v->offer_id}") ? $union("o{$v->offer_id}", "v{$v->id}") : $report[] = "связь ТС #{$v->id} ↔ предложение #{$v->offer_id} спорит, не склеено";
            }
        }
        foreach ($lots as $p) {
            if ($p->offer_id && isset($offers[$p->offer_id])) {
                $fits("p{$p->id}", "o{$p->offer_id}") ? $union("o{$p->offer_id}", "p{$p->id}") : $report[] = "связь лота #{$p->id} ↔ предложение #{$p->offer_id} спорит, не склеено";
            }
        }
        // Один номер убытка или ДЛ — одна машина; один VIN — если номера не спорят.
        foreach (['key' => fn ($n, $row) => $n[0] === 'p' ? Code::key($row->dl) : ($n[0] === 'v' ? $row->ref_key : (! $row->is_demo ? $row->claim_ref_key : null)),
            'vin' => fn ($n, $row) => strlen((string) $row->vin) === 17 && ! ($row->is_demo ?? false) ? $row->vin : null] as $what => $value) {
            $groups = [];
            foreach (['v' => $vehicles, 'o' => $offers, 'p' => $lots] as $kind => $rows) {
                foreach ($rows as $row) {
                    if ($k = $value($kind, $row)) {
                        $groups[$k][] = $kind.$row->id;
                    }
                }
            }
            foreach ($groups as $k => $nodes) {
                for ($i = 1; $i < count($nodes); $i++) {
                    $fits($nodes[0], $nodes[$i]) ? $union($nodes[0], $nodes[$i]) : ($what === 'key' ? $report[] = "номер {$k}: {$nodes[0]} и {$nodes[$i]} не склеены (две продажи или два хранения)" : null);
                }
            }
        }

        $components = [];
        foreach (array_keys($parent) as $x) {
            $components[$find($x)][] = $x;
        }
        $now = now();
        foreach ($components as $members) {
            $byKind = ['v' => [], 'o' => [], 'p' => []];
            foreach ($members as $m) {
                $byKind[$m[0]][] = (int) substr($m, 1);
            }
            $sources = [...array_map(fn ($id) => [0, $vehicles[$id]], $byKind['v']), ...array_map(fn ($id) => [1, $offers[$id]], $byKind['o']),
                ...array_map(fn ($id) => [2, $lots[$id]], collect($byKind['p'])->sortDesc()->all())];
            $car = ['created_at' => collect($sources)->min(fn ($s) => $s[1]->created_at) ?? $now, 'updated_at' => $now,
                'is_demo' => collect($byKind['o'])->contains(fn ($id) => (bool) $offers[$id]->is_demo)];
            foreach (self::SOURCES as $column => $from) {
                $seen = [];
                foreach ($sources as [$lane, $row]) {
                    $field = $from[$lane];
                    $v = $field ? $row->{$field} : null;
                    if ($v === null || $v === '' || $v === '[]' || ($column === 'oversize' && ! $v)) {
                        continue;
                    }
                    $seen[] = [$lane, $v];
                }
                if ($seen) {
                    $car[$column] = $seen[0][1];
                    $distinct = array_unique(array_map(fn ($s) => mb_strtolower(trim(is_string($s[1]) ? preg_replace('/\s+/u', ' ', $s[1]) : json_encode($s[1]))), $seen));
                    if (count($distinct) > 1 && ! in_array($column, ['damage_zones', 'flags', 'docs_required', 'category'], true)) {
                        $report[] = 'машина '.implode(' ', $members)." {$column}: ".implode(' | ', array_map(fn ($s) => ['ТС', 'предложение', 'лот'][$s[0]].' '.(is_string($s[1]) ? $s[1] : json_encode($s[1], JSON_UNESCAPED_UNICODE)), $seen));
                    }
                }
            }
            $car['claim_ref_key'] = isset($car['claim_ref']) ? Code::key($car['claim_ref']) : null;
            $car['active'] = ! $car['is_demo'] && (collect($byKind['o'])->contains(fn ($id) => ! in_array($offers[$id]->state, self::CLOSED_OFFER, true))
                || collect($byKind['v'])->contains(fn ($id) => ! in_array($vehicles[$id]->state, self::CLOSED_VEHICLE, true)));
            $id = DB::table('cars')->insertGetId($car);
            foreach (['v' => 'park_vehicles', 'o' => 'offers', 'p' => 'purchase_cars'] as $kind => $table) {
                if ($byKind[$kind]) {
                    DB::table($table)->whereIn('id', $byKind[$kind])->update(['car_id' => $id]);
                }
            }
        }

        // Перевозка — машины своего предложения или ТС.
        DB::statement('update transports t set car_id = coalesce((select car_id from offers where id = t.offer_id), (select car_id from park_vehicles where id = t.vehicle_id))');
        // Файлы парковки (сняли на ней, пришли письмом её ящика — не принесённые предложением и не из CRM) — с меткой
        // `park`: CRM их прячет, но не удаляет, выдача стирает её письма (`Sale::parkOwned`, `PurgeLetters`).
        DB::update("update media set custom_properties = (custom_properties::jsonb || '{\"park\": true}'::jsonb)::json where model_type = ? and coalesce(custom_properties->>'source', '') <> 'crm' and custom_properties->'offer' is null",
            ['App\\Park\\Vehicle']);
        // Файлы — машины. Метка `offer` (чьи файлы принесло предложение, чтобы при отвязке увезти) больше не нужна.
        foreach (['App\\Offers\\Offer' => 'offers', 'App\\Park\\Vehicle' => 'park_vehicles', 'App\\Purchases\\Car' => 'purchase_cars'] as $type => $table) {
            DB::update("update media m set model_type = ?, model_id = l.car_id, custom_properties = (m.custom_properties::jsonb - 'offer')::json from {$table} l where m.model_type = ? and m.model_id = l.id",
                ['App\\Cars\\Car', $type]);
        }

        if ($report) {
            @file_put_contents(storage_path('logs/cars-backfill.log'), now()->toDateTimeString()."\n".implode("\n", $report)."\n", FILE_APPEND);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Машины обратно на дорожки не раскладываются: откат — из бэкапа базы');
    }
};
