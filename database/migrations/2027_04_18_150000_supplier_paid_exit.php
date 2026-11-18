<?php

use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Outcome;
use App\Workflow\Presets\Route;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Поставщик подтвердил оплату» у шага «Оплата счёта» живых маршрутов (18.11.2026, владелец по 2610142050: Альфа написала
 * «Денежные средства на счету компании», а сделка просила платёжку). Шаг, где менеджер отвечает «Оплатил, документ приложен», а
 * в его блоке поставщик подтверждает «Оплата получена», получает исход сотрудника туда же — мимо письма с платёжкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Workflow::where('track', Track::Sale->value)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get();
            $added = false;
            foreach ($stages as $pay) {
                $paid = fn (Outcome $x) => $x->actor->value === 'manager' && mb_strtolower(trim($x->label)) === 'оплатил, документ приложен';
                if (! $pay->exits->contains($paid) || $pay->exits->contains(fn (Outcome $x) => $x->label === Route::SUPPLIER_PAID)) {
                    continue;
                }
                $confirm = $stages->first(fn (Stage $s) => $s->block_id === $pay->block_id && $s->waits_for?->value === 'supplier'
                    && $s->exits->contains(fn (Outcome $x) => mb_strtolower(trim($x->label)) === 'оплата получена'));
                $to = $confirm?->exits->first(fn (Outcome $x) => mb_strtolower(trim($x->label)) === 'оплата получена')?->to_stage_id;
                if (! $to) {
                    continue;
                }
                DB::table('workflow_exits')->insert([
                    'stage_id' => $pay->id, 'to_stage_id' => $to, 'label' => Route::SUPPLIER_PAID, 'actor' => 'staff',
                    'position' => (int) $pay->exits->max('position') + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $added = true;
            }
            $added && app(RevalidateWorkflow::class)($workflow);
        }
    }

    public function down(): void
    {
        DB::table('workflow_exits')->where('label', Route::SUPPLIER_PAID)->delete();
    }
};
