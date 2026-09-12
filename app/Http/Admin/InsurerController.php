<?php

namespace App\Http\Admin;

use App\Workflow\Insurer;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Http\Request;

class InsurerController
{
    public function index()
    {
        return view('admin.insurers.index', [
            'insurers' => Insurer::with('workflows')->withCount('offers')->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:insurers,name']]);
        $insurer = Insurer::create($data);

        return redirect("/nastroyki/strahovye/{$insurer->id}");
    }

    public function show(Request $request, Insurer $insurer)
    {
        $track = Track::tryFrom($request->query('vetka', '')) ?? Track::Sale;
        $workflow = $insurer->workflowOrNew($track);
        $workflow->load(['blocks.stages.exits.to', 'blocks.stages.block']);

        return view('admin.insurers.show', [
            'insurer' => $insurer,
            'track' => $track,
            'workflow' => $workflow,
            'problems' => $workflow->problems(),
            'occupied' => Position::whereIn('stage_id', $workflow->stages()->pluck('workflow_stages.id'))
                ->selectRaw('stage_id, count(*) as n')->groupBy('stage_id')->pluck('n', 'stage_id'),
        ]);
    }

    public function update(Request $request, Insurer $insurer)
    {
        $insurer->update($request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:insurers,name,'.$insurer->id],
            'is_active' => ['boolean'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('toast', 'Сохранено');
    }

    public function destroy(Insurer $insurer)
    {
        if ($insurer->offers()->exists()) {
            return back()->withErrors(['insurer' => 'У страховой есть предложения']);
        }
        $insurer->delete();

        return redirect('/nastroyki/strahovye')->with('toast', 'Удалена');
    }
}
