<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Цена менеджера за машину — одна таблица (29.10.2026, владелец: «это же одна и та же машина»): цены за лоты закупки
 * (`purchase_offers`) переезжают в подтверждения `bids` видом `purchase` с `purchase_car_id` и без предложения. Состояния
 * те же, «выбрана» — `accepted`; прежняя цена, снятая новой той же секундой, — `replaced`, как у подтверждения продажи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $t) {
            $t->unsignedBigInteger('offer_id')->nullable()->change();
            $t->foreignId('purchase_car_id')->nullable()->after('offer_id')->constrained()->cascadeOnDelete();
        });
        DB::statement("create unique index bids_one_active_lot on bids (purchase_car_id, user_id) where state = 'active' and purchase_car_id is not null");
        DB::statement('create index bids_purchase_car_id_state_index on bids (purchase_car_id, state) where purchase_car_id is not null');

        $prices = DB::table('purchase_offers')->orderBy('id')->get();
        $replaced = [];
        foreach ($prices->groupBy(fn ($p) => $p->car_id.':'.$p->user_id) as $mine) {
            foreach ($mine->values() as $i => $price) {
                $next = $mine[$i + 1] ?? null;
                if ($next && $price->state === 'withdrawn' && abs(strtotime($next->created_at) - strtotime($price->updated_at)) <= 2) {
                    $replaced[$price->id] = true;
                }
            }
        }
        $rows = $prices->map(fn ($p) => [
            'purchase_car_id' => $p->car_id, 'user_id' => $p->user_id, 'kind' => 'purchase', 'amount' => $p->amount, 'comment' => $p->comment,
            'state' => isset($replaced[$p->id]) ? 'replaced' : ($p->state === 'chosen' ? 'accepted' : $p->state),
            'decided_at' => in_array($p->state, ['chosen', 'declined'], true) ? $p->updated_at : null,
            'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
        ])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bids')->insert($chunk);
        }
        Schema::drop('purchase_offers');
    }

    public function down(): void
    {
        Schema::create('purchase_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('car_id')->constrained('purchase_cars')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('amount');
            $t->string('comment', 500)->nullable();
            $t->string('state', 16)->default('active');
            $t->timestamps();
        });
        foreach (DB::table('bids')->where('kind', 'purchase')->orderBy('id')->get() as $b) {
            DB::table('purchase_offers')->insert(['car_id' => $b->purchase_car_id, 'user_id' => $b->user_id, 'amount' => $b->amount, 'comment' => $b->comment,
                'state' => ['accepted' => 'chosen', 'replaced' => 'withdrawn'][$b->state] ?? $b->state, 'created_at' => $b->created_at, 'updated_at' => $b->updated_at]);
        }
        DB::table('bids')->where('kind', 'purchase')->delete();
        DB::statement('drop index bids_one_active_lot');
        DB::statement('drop index bids_purchase_car_id_state_index');
        Schema::table('bids', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_car_id');
            $t->unsignedBigInteger('offer_id')->nullable(false)->change();
        });
    }
};
