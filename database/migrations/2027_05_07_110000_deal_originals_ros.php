<?php

use App\Offers\Originals;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оригиналы у Альфы и Т при договоре на Р.О.С. или ПРАЙМ (26.11.2026, владелец: «нужны оригиналы ДКП, если договор не на
 * покупателя, а на прайм или на рос; когда счёт на рос — нам все пакеты документов нужны, сканы и оригиналы, рос как покупатель
 * выступает»). `deal_originals.needs_scan` — документ закрыт, только когда есть и скан. Маршруты: у Альфы «Договор подписан»,
 * у Т «Оплата получена» ведут веткой `ros` (за ТС платят нам, `DealScheme::paysCar`) в «Оригиналы», остальным — как было в
 * «Сделку закрыта». У Т шагу — свой блок «Оформление документов» перед закрытием.
 */
return new class extends Migration
{
    /** @var array<string, string> вендор → шаг, чьи исходы в «Сделку закрыта» разводим */
    private const ROUTES = ['АльфаСтрахование' => 'Подписание', 'Т-Страхование' => 'Проверка оплаты подбора'];

    public function up(): void
    {
        Schema::table('deal_originals', function (Blueprint $t) {
            $t->boolean('needs_scan')->default(false);
        });

        foreach (self::ROUTES as $vendor => $last) {
            $workflow = Workflow::where('track', Track::Sale->value)->whereHas('vendor', fn ($q) => $q->where('name', $vendor))->first();
            $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name') : collect();
            $from = $stages[$last] ?? null;
            $closed = $stages['Сделка закрыта'] ?? null;
            if (! $from || ! $closed || isset($stages[Originals::STAGE])) {
                continue;
            }
            DB::transaction(function () use ($workflow, $from, $closed) {
                // У Альфы шаг — в блоке подписания следом за «Подписанием»; у Т своего блока документов нет — заводим перед закрытием.
                if ($from->name === 'Подписание') {
                    $block = $from->block_id;
                    $position = $from->position + 1;
                } else {
                    $won = Block::find($closed->block_id);
                    Block::where('workflow_id', $workflow->id)->where('position', '>=', $won->position)->increment('position');
                    $block = Block::create(['workflow_id' => $workflow->id, 'name' => 'Оформление документов', 'text' => 'Собираем сканы и оригиналы документов', 'position' => $won->position])->id;
                    $position = 0;
                }
                Stage::where('block_id', $block)->where('position', '>=', $position)->increment('position');
                $originals = Stage::create([
                    'workflow_id' => $workflow->id, 'block_id' => $block, 'position' => $position, 'name' => Originals::STAGE,
                    'waits_for' => 'us', 'limit_minutes' => 14 * 1440, 'deadline_source' => 'own', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
                    'ask_text' => 'Собираем сканы и оригиналы документов',
                ]);
                $originals->exits()->create(['label' => Originals::DONE, 'actor' => 'staff', 'to_stage_id' => $closed->id, 'position' => 0]);
                // Каждый исход в закрытие — двумя: за ТС платят нам — в «Оригиналы», нет — как было.
                foreach ($from->exits->where('to_stage_id', $closed->id)->whereNull('branch') as $exit) {
                    $exit->update(['branch' => Outcome::NOT_ROS]);
                    $from->exits()->create(['label' => $exit->label, 'actor' => $exit->actor, 'confirm' => $exit->confirm, 'to_stage_id' => $originals->id,
                        'position' => $exit->position, 'branch' => Outcome::ROS]);
                }
                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void
    {
        foreach (self::ROUTES as $vendor => $last) {
            $workflow = Workflow::where('track', Track::Sale->value)->whereHas('vendor', fn ($q) => $q->where('name', $vendor))->first();
            $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
            if (! ($originals = $stages[Originals::STAGE] ?? null) || ! ($from = $stages[$last] ?? null) || ! ($closed = $stages['Сделка закрыта'] ?? null)) {
                continue;
            }
            Outcome::where('stage_id', $from->id)->where('to_stage_id', $originals->id)->delete();
            Outcome::where('stage_id', $from->id)->where('to_stage_id', $closed->id)->where('branch', Outcome::NOT_ROS)->update(['branch' => null]);
            \App\Workflow\Position::where('stage_id', $originals->id)->update(['stage_id' => $from->id]);
            $originals->exits()->delete();
            $block = $originals->block_id;
            $originals->delete();
            if ($from->block_id !== $block && ! Stage::where('block_id', $block)->exists()) {
                $b = Block::find($block);
                $b?->delete();
                Block::where('workflow_id', $workflow->id)->where('position', '>', $b?->position ?? PHP_INT_MAX)->decrement('position');
            }
        }
        Schema::table('deal_originals', fn (Blueprint $t) => $t->dropColumn('needs_scan'));
    }
};
