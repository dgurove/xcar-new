<?php

namespace App\Http\Admin;

use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\CarPlace;
use App\Offers\OfferState;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\DeleteBlock;
use App\Workflow\Actions\DeleteStage;
use App\Workflow\Actions\ReorderStages;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Actions\SaveBlock;
use App\Workflow\Actions\SaveStage;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Block;
use App\Workflow\DeadlineSource;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\WaitsFor;
use App\Workflow\Workflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkflowController
{
    private function back(Workflow $workflow): string
    {
        return "/settings/insurers/{$workflow->insurer_id}?vetka={$workflow->track->value}";
    }

    public function activate(Request $request, Workflow $workflow, RevalidateWorkflow $revalidate)
    {
        $revalidate($workflow, $request->boolean('active'));

        return redirect($this->back($workflow))->with('toast', $workflow->is_active ? 'Маршрут включён' : 'Маршрут выключен');
    }

    public function autoStart(Request $request, Workflow $workflow)
    {
        $workflow->update(['auto_start' => $request->boolean('auto_start')]);

        return redirect($this->back($workflow))->with('toast', $workflow->auto_start ? 'Для каждого автомобиля' : 'По кнопке на карточке');
    }

    public function fill(Request $request, Workflow $workflow, ApplyPreset $apply)
    {
        $preset = Preset::from($request->validate(['preset' => ['required', Rule::enum(Preset::class)]])['preset']);
        $apply($workflow, $preset);

        return redirect($this->back($workflow))->with('toast', 'Маршрут заполнен');
    }

    public function reorder(Request $request, Workflow $workflow, ReorderStages $reorder)
    {
        $reorder($workflow, $request->validate(['blocks' => ['required', 'array'], 'blocks.*.id' => ['required', 'integer'], 'blocks.*.stages' => ['array']])['blocks']);

        return response()->noContent();
    }

    public function storeBlock(Request $request, Workflow $workflow, SaveBlock $save)
    {
        $save($workflow, $request->validate(['name' => ['required', 'string', 'max:80'], 'text' => ['nullable', 'string', 'max:1000']]));

        return redirect($this->back($workflow));
    }

    public function updateBlock(Request $request, Block $block, SaveBlock $save)
    {
        $save($block->workflow, $request->validate(['name' => ['required', 'string', 'max:80'], 'text' => ['nullable', 'string', 'max:1000']]), $block);

        return redirect($this->back($block->workflow))->with('toast', 'Сохранено');
    }

    public function destroyBlock(Block $block, DeleteBlock $delete)
    {
        $delete($block);

        return redirect($this->back($block->workflow));
    }

    public function createStage(Request $request, Workflow $workflow)
    {
        $block = $workflow->blocks()->findOrFail($request->query('blok'));

        return $this->stageForm($workflow, new Stage(['block_id' => $block->id, 'waits_for' => WaitsFor::Us, 'deadline_source' => DeadlineSource::Own, 'asks' => Asks::Nothing]));
    }

    public function editStage(Stage $stage)
    {
        $stage->load(['exits', 'block']);

        return $this->stageForm($stage->workflow, $stage);
    }

    public function storeStage(Request $request, Workflow $workflow, SaveStage $save)
    {
        $data = $this->validateStage($request, $workflow);
        $save($workflow, $workflow->blocks()->findOrFail($data['block_id']), $data);

        return redirect($this->back($workflow))->with('toast', 'Этап добавлен');
    }

    public function updateStage(Request $request, Stage $stage, SaveStage $save)
    {
        $workflow = $stage->workflow;
        $data = $this->validateStage($request, $workflow);
        $save($workflow, $workflow->blocks()->findOrFail($data['block_id']), $data, $stage);

        return redirect($this->back($workflow))->with('toast', 'Сохранено');
    }

    public function destroyStage(Stage $stage, DeleteStage $delete)
    {
        $workflow = $stage->workflow;
        $delete($stage);

        return redirect($this->back($workflow))->with('toast', 'Этап удалён');
    }

    private function stageForm(Workflow $workflow, Stage $stage)
    {
        $workflow->load('blocks.stages');

        return view('admin.insurers.stage', [
            'workflow' => $workflow,
            'stage' => $stage,
            'targets' => $workflow->blocks->flatMap(fn (Block $b) => $b->stages->map(fn (Stage $s) => [$s->id, $b->name.' › '.$s->name]))->mapWithKeys(fn ($p) => [$p[0] => $p[1]])->all(),
            'exits' => old('exits', $stage->exists ? $stage->exits->map(fn ($e) => $e->only('id', 'label', 'actor', 'to_stage_id', 'confirm'))->all() : []),
            'fields' => old('fields', $stage->fields ?? []),
            'staffFields' => old('staff_fields', $stage->staff_fields ?? []),
            'templates' => Template::where('scope', Scope::Offers)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    private function validateStage(Request $request, Workflow $workflow): array
    {
        return $request->validate([
            'block_id' => ['required', Rule::exists('workflow_blocks', 'id')->where('workflow_id', $workflow->id)],
            'name' => ['required', 'string', 'max:80'],
            'waits_for' => ['required', Rule::enum(WaitsFor::class)],
            'limit_value' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limit_unit' => ['nullable', Rule::in(['minutes', 'hours', 'days'])],
            'deadline_source' => ['required', Rule::enum(DeadlineSource::class)],
            'offer_state' => ['nullable', Rule::enum(OfferState::class)],
            'car_place' => ['nullable', Rule::enum(CarPlace::class)],
            'ask_title' => ['nullable', 'string', 'max:120'],
            'ask_text' => ['nullable', 'string', 'max:2000'],
            'asks' => ['required', Rule::enum(Asks::class)],
            'template_id' => ['nullable', 'exists:mail_templates,id'],
            'fields' => ['nullable', 'array'],
            'fields.*.key' => ['nullable', 'string', 'max:40'],
            'fields.*.label' => ['nullable', 'string', 'max:80'],
            'fields.*.type' => ['nullable', 'string'],
            'staff_fields' => ['nullable', 'array'],
            'staff_fields.*.key' => ['nullable', 'string', 'max:40'],
            'staff_fields.*.label' => ['nullable', 'string', 'max:80'],
            'staff_fields.*.type' => ['nullable', 'string'],
            'exits' => ['nullable', 'array'],
            'exits.*.id' => ['nullable', 'integer'],
            'exits.*.label' => ['nullable', 'string', 'max:60'],
            'exits.*.actor' => ['nullable', Rule::enum(Actor::class)],
            'exits.*.to_stage_id' => ['nullable', Rule::exists('workflow_stages', 'id')->where('workflow_id', $workflow->id)],
            'exits.*.confirm' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
