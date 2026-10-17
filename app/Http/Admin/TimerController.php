<?php

namespace App\Http\Admin;

use App\Support\Plural;
use App\Workflow\Actions\SaveStage;
use App\Workflow\Actions\SetStageLimit;
use App\Workflow\DeadlineSource;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\StageStats;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use App\Workflow\Workflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Настройки → Таймеры» (19.01.2027, начальник «постоянно говорит про таймеры»): сроки всех этапов всех вендоров одной таблицей
 * вместо четырёх маршрутов и шестидесяти страниц этапов. Строка — этап (одинаковые имена у разных вендоров — одна строка),
 * столбец — вендор; в ячейке срок, сколько сделок на этапе сейчас и сколько просрочено, сколько этап занимает на деле
 * (`StageStats`). Нажатие — шторка срока (`?edit=этап`), «Всем вендорам» — та же для строки (`?row=имя`); новый срок доходит
 * до сделок на этапе (`SetStageLimit` → `RetimeStage`).
 */
class TimerController
{
    public function index(Request $request)
    {
        $workflows = Workflow::where('track', Track::Sale)->whereHas('vendor')->with(['vendor', 'blocks' => fn ($b) => $b->orderBy('position'), 'blocks.stages' => fn ($s) => $s->orderBy('position')])
            ->get()->sortBy(fn (Workflow $w) => $w->vendor->name)->values();
        $rows = [];
        foreach ($workflows as $w) {
            foreach ($w->blocks as $block) {
                foreach ($block->stages as $stage) {
                    // Конечные этапы («Сделка закрыта») — без хода и часов: в таблице им нечего настраивать.
                    if ($stage->waits_for === WaitsFor::Nobody) {
                        continue;
                    }
                    $stage->setRelation('workflow', $w);
                    $rows[$stage->name] ??= ['name' => $stage->name, 'block' => $block->name, 'cells' => []];
                    $rows[$stage->name]['cells'][$w->id] = $stage;
                }
            }
        }
        $counts = Position::selectRaw('stage_id, count(*) as n, count(*) filter (where deadline_at < now()) as late')->groupBy('stage_id')->get()->keyBy('stage_id');
        $edit = $request->integer('edit') ? Stage::with('workflow.vendor')->find($request->integer('edit')) : null;
        $row = is_string($request->query('row')) ? ($rows[$request->query('row')] ?? null) : null;

        return view('admin.timers.index', [
            'workflows' => $workflows, 'rows' => array_values($rows), 'counts' => $counts, 'stats' => StageStats::medians(),
            'edit' => $edit, 'row' => $row,
        ]);
    }

    public function update(Request $request, Stage $stage, SetStageLimit $set)
    {
        $data = $this->limit($request);
        $result = $set($stage, SaveStage::minutes($data), ($data['limit_unit'] ?? null) === 'workdays', $request->boolean('retime', true), $request->user());

        return $this->done($result, $stage->name.' · '.$stage->workflow->vendor?->name);
    }

    /** «Всем вендорам»: один срок этапу с этим именем у каждого вендора, где срок свой. */
    public function updateRow(Request $request, SetStageLimit $set)
    {
        $data = $this->limit($request) + $request->validate(['name' => ['required', 'string', 'max:80']]);
        $total = ['retimed' => 0, 'problems' => []];
        foreach (Stage::where('name', $data['name'])->where('deadline_source', DeadlineSource::Own->value)->where('waits_for', '!=', WaitsFor::Nobody->value)->get() as $stage) {
            $r = $set($stage, SaveStage::minutes($data), ($data['limit_unit'] ?? null) === 'workdays', $request->boolean('retime', true), $request->user());
            $total['retimed'] += $r['retimed'];
            $total['problems'] = [...$total['problems'], ...$r['problems']];
        }

        return $this->done($total, $data['name'].' · всем вендорам');
    }

    private function limit(Request $request): array
    {
        return $request->validate([
            'limit_value' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limit_unit' => ['nullable', Rule::in(['minutes', 'hours', 'days', 'workdays'])],
            'retime' => ['nullable', 'boolean'],
        ]);
    }

    private function done(array $result, string $what)
    {
        $back = redirect('/settings/timers');
        if ($result['problems']) {
            return $back->with('toast-danger', 'Сохранено, но маршрут выключен: '.$result['problems'][0]);
        }

        return $back->with('toast', $what.($result['retimed'] ? ', новый срок у '.$result['retimed'].' '.Plural::of($result['retimed'], ['сделки', 'сделок', 'сделок']) : ''));
    }
}
