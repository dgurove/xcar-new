<?php

use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Три дорожки (06.10.2026): гаражная сделка кончается бумагами, забрать машину — дело вывоза. В боевых маршрутах
 * продажи (по именам этапов, как заготовки `Route::garageSegment`, `forked`, `Stock`):
 * — «Выдача автомобиля — в гараж» снимается: «Документы получены» ведёт в «Сделка закрыта», стоявшие на ней — туда же;
 * — у «Подписания документов» с развилкой «кто забирает» — третий исход гаражной сделке (`keeps`) в «Сделка закрыта»;
 * — у Каркаде «Поставщику оплачено» — обычной сделке к выдаче на площадке (`deal`), гаражной — в «Сделка закрыта»;
 * — идущие гаражные сделки на этапах передачи — в «Сделка закрыта»;
 * — Совкомбанк: срок страховой у черновика больше не уводит сам — «Покупатель от поставщика» кнопкой сотрудника.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enter = app(EnterStage::class);
        foreach (Workflow::where('track', Track::Sale)->get() as $workflow) {
            DB::transaction(function () use ($workflow, $enter) {
                $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name');
                $closed = $stages['Сделка закрыта'] ?? null;
                if (! $closed) {
                    return;
                }
                $touched = false;

                if ($pickup = $stages['Выдача автомобиля — в гараж'] ?? null) {
                    DB::table('workflow_exits')->where('to_stage_id', $pickup->id)->where('stage_id', '!=', $pickup->id)->update(['to_stage_id' => $closed->id]);
                    Position::where('stage_id', $pickup->id)->with('offer')->get()->each(fn (Position $p) => $enter($p->offer, $closed));
                    $blockId = $pickup->block_id;
                    $pickup->delete();
                    DB::table('workflow_blocks')->where('id', $blockId)
                        ->whereNotExists(fn ($q) => $q->from('workflow_stages')->whereColumn('workflow_stages.block_id', 'workflow_blocks.id'))->delete();
                    $touched = true;
                }

                if (($signing = $stages['Подписание документов'] ?? null) && $signing->exits->contains(fn ($e) => in_array($e->branch, [Outcome::WE_HAND, Outcome::BUYER_PICKS], true))
                    && ! $signing->exits->contains(fn ($e) => $e->branch === Outcome::KEEPS)) {
                    $hands = $signing->exits->firstWhere('branch', Outcome::WE_HAND);
                    Outcome::create(['stage_id' => $signing->id, 'to_stage_id' => $closed->id, 'label' => $hands->label, 'actor' => $hands->actor, 'position' => $signing->exits->max('position') + 1, 'branch' => Outcome::KEEPS]);
                    $touched = true;
                }

                $supplier = $stages['Оплата поставщику'] ?? null;
                $plain = $supplier?->exits->first(fn ($e) => $e->label === 'Поставщику оплачено' && ! $e->branch);
                if ($plain && ($stages['Выдача на площадке поставщика'] ?? null)) {
                    $plain->update(['branch' => Outcome::DEAL]);
                    Outcome::create(['stage_id' => $supplier->id, 'to_stage_id' => $closed->id, 'label' => $plain->label, 'actor' => $plain->actor, 'position' => $supplier->exits->max('position') + 1, 'branch' => Outcome::KEEPS]);
                    $touched = true;
                }

                // Идущие гаражные сделки на передаче — бумаги готовы, машину везёт вывоз.
                $handover = $stages->only(['Передача автомобиля покупателю', 'Менеджер забирает автомобиль', 'Выдача на площадке поставщика'])->pluck('id');
                if ($touched && $handover->isNotEmpty()) {
                    Position::whereIn('stage_id', $handover)->where('track', Track::Sale)->with('offer')->get()
                        ->filter(fn (Position $p) => $p->offer->deal()->first()?->isGarage())
                        ->each(fn (Position $p) => $enter($p->offer, $closed));
                }

                // Совкомбанк: черновик к покупателю поставщика — только руками.
                if ($insurer = $stages['Запрос покупателя у поставщика'] ?? null) {
                    $timed = DB::table('workflow_exits')->where('to_stage_id', $insurer->id)->where('actor', Actor::Timer->value);
                    if ($timed->exists()) {
                        $timed->update(['actor' => Actor::Staff->value, 'label' => 'Покупатель от поставщика']);
                        $touched = true;
                    }
                }

                if ($touched && ($problems = app(RevalidateWorkflow::class)($workflow->fresh()))) {
                    Log::warning('three_lanes_routes: маршрут '.$workflow->id.' погас', $problems);
                }
            });
        }
    }

    public function down(): void {}
};
