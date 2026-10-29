<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * История машины — одна (29.10.2026, владелец: «машина в закупке и в предложениях — одна и та же, с продолжающейся
 * историей»). Журнал предложения `offer_events` становится журналом машины `car_events`: у строки — машина (`car_id`) и
 * дорожка, где это было (`offer_id` — продажа, `purchase_car_id` — лот закупки). До сих пор у закупки журнала не было
 * вовсе — прошлое восстанавливается из того, что лежит в таблицах: лот появился (`purchase_cars.created_at`), каждая цена
 * менеджера (`purchase_offers`: названа — `created_at`, отозвана, выбрана или не выбрана — `updated_at`), наша цена —
 * одной строкой в момент переноса в предложения (раньше её время не хранилось).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('offer_events', 'car_events');
        Schema::table('car_events', function (Blueprint $t) {
            $t->foreignId('car_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_car_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('offer_id')->nullable()->change();
        });
        DB::statement('update car_events e set car_id = o.car_id from offers o where o.id = e.offer_id');

        $this->purchases();

        Schema::table('car_events', function (Blueprint $t) {
            $t->unsignedBigInteger('car_id')->nullable(false)->change();
            $t->index(['car_id', 'created_at']);
        });
    }

    private function purchases(): void
    {
        $lots = DB::table('purchase_cars as c')->join('purchases as p', 'p.id', '=', 'c.purchase_id')
            ->select('c.id', 'c.car_id', 'c.price_final', 'c.created_at', 'p.number', 'p.imported_by', 'p.imported_at')->get()->keyBy('id');
        $dl = DB::table('cars')->whereIn('id', $lots->pluck('car_id'))->pluck('claim_ref', 'id');
        $rows = [];
        $row = fn ($lot, ?int $user, string $type, array $payload, $at) => [
            'car_id' => $lot->car_id, 'purchase_car_id' => $lot->id, 'user_id' => $user, 'type' => $type,
            'payload' => json_encode(['purchase' => $lot->number] + $payload, JSON_UNESCAPED_UNICODE), 'created_at' => $at,
        ];
        foreach ($lots as $lot) {
            $rows[] = $row($lot, $lot->imported_by, 'created', ['dl' => $dl[$lot->car_id] ?? null], $lot->created_at ?? $lot->imported_at);
        }

        // Новая цена того же человека снимала прежнюю той же секундой — это смена цены («было → стало»), а не отзыв. Выбор и
        // «не выбрана» — действие админа; кто выбрал, не хранилось — строкой без автора.
        $prices = DB::table('purchase_offers')->orderBy('id')->get();
        $replaced = [];
        $was = [];
        foreach ($prices->groupBy(fn ($p) => $p->car_id.':'.$p->user_id) as $mine) {
            foreach ($mine->values() as $i => $price) {
                $next = $mine[$i + 1] ?? null;
                if ($next && $price->state === 'withdrawn' && abs(strtotime($next->created_at) - strtotime($price->updated_at)) <= 2) {
                    $replaced[$price->id] = true;
                    $was[$next->id] = (int) $price->amount;
                }
            }
        }
        foreach ($prices as $price) {
            $lot = $lots[$price->car_id] ?? null;
            if (! $lot) {
                continue;
            }
            $rows[] = $row($lot, $price->user_id, 'bid_placed', array_filter(['amount' => (int) $price->amount, 'was' => $was[$price->id] ?? null]), $price->created_at);
            $type = isset($replaced[$price->id]) ? null : (['withdrawn' => 'bid_withdrawn', 'chosen' => 'bid_accepted', 'declined' => 'bid_declined'][$price->state] ?? null);
            if ($type) {
                $rows[] = $row($lot, $type === 'bid_withdrawn' ? $price->user_id : null, $type, ['amount' => (int) $price->amount, 'manager' => $price->user_id], $price->updated_at);
            }
        }

        // Наша цена — секундой раньше переноса в предложения; лот, что не уходил, — когда лот правили последний раз.
        $moved = DB::table('car_events')->where('type', 'created')->whereRaw("jsonb_exists(payload, 'purchase')")->whereNull('purchase_car_id')
            ->get(['car_id', 'user_id', 'created_at'])->keyBy('car_id');
        foreach ($lots->whereNotNull('price_final') as $lot) {
            $at = isset($moved[$lot->car_id]) ? Carbon::parse($moved[$lot->car_id]->created_at)->subSecond()
                : DB::table('purchase_cars')->where('id', $lot->id)->value('updated_at');
            $rows[] = $row($lot, $moved[$lot->car_id]->user_id ?? null, 'updated', ['changes' => ['price_final' => [null, (int) $lot->price_final]]], $at);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('car_events')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('car_events')->whereNull('offer_id')->delete();
        Schema::table('car_events', function (Blueprint $t) {
            $t->dropIndex(['car_id', 'created_at']);
            $t->dropConstrainedForeignId('purchase_car_id');
            $t->dropConstrainedForeignId('car_id');
            $t->unsignedBigInteger('offer_id')->nullable(false)->change();
        });
        Schema::rename('car_events', 'offer_events');
    }
};
