<?php

namespace App\Http\Admin;

use App\Offers\Actions\SyncViewers;
use App\Offers\Audience;
use App\Offers\AudienceRules;
use App\Users\ManagerGroup;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Кому показывать — настройки: группы менеджеров и шаблоны волн. Состав группы или шаблон вендора
 * поменялся — опубликованные пересчитываются (SyncViewers::all), новым видящим уходит уведомление часами.
 */
class AudienceController
{
    public function index()
    {
        return view('admin.audience.index', [
            'groups' => ManagerGroup::with('members')->orderBy('position')->orderBy('name')->get(),
            'presets' => Audience::orderBy('position')->orderBy('name')->get(),
            'managers' => User::where('role', Role::Manager)->orderBy('name')->get(),
            'audienceOptions' => AudienceRules::options(presets: false),
        ]);
    }

    public function storeGroup(Request $request, SyncViewers $sync)
    {
        $data = $this->group($request);
        $group = ManagerGroup::create(['name' => $data['name'], 'position' => (int) ManagerGroup::max('position') + 1]);
        $group->members()->sync($data['members'] ?? []);
        $sync->all();

        return redirect('/settings/audience')->with('toast', 'Группа добавлена');
    }

    public function updateGroup(Request $request, ManagerGroup $group, SyncViewers $sync)
    {
        $data = $this->group($request);
        $group->update(['name' => $data['name']]);
        $group->members()->sync($data['members'] ?? []);
        $sync->all();

        return redirect('/settings/audience')->with('toast', 'Сохранено');
    }

    public function destroyGroup(ManagerGroup $group, SyncViewers $sync)
    {
        $group->delete();
        $sync->all();

        return redirect('/settings/audience')->with('toast', 'Группа удалена');
    }

    public function storePreset(Request $request, SyncViewers $sync)
    {
        Audience::create([...$this->preset($request), 'position' => (int) Audience::max('position') + 1]);

        return redirect('/settings/audience')->with('toast', 'Шаблон добавлен');
    }

    public function updatePreset(Request $request, Audience $audience, SyncViewers $sync)
    {
        $audience->update($this->preset($request));
        // Предложения со своими правилами хранят снимок; без своих — идут за шаблоном вендора и пересчитываются.
        $sync->all();

        return redirect('/settings/audience')->with('toast', 'Сохранено');
    }

    public function destroyPreset(Audience $audience, SyncViewers $sync)
    {
        $audience->delete();
        $sync->all();

        return redirect('/settings/audience')->with('toast', 'Шаблон удалён');
    }

    private function group(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', 'exists:users,id'],
        ]);
    }

    private function preset(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'rules' => ['nullable', 'json']]);
        $rules = AudienceRules::normalize($data['rules'] ?? null);
        if (! $rules) {
            throw ValidationException::withMessages(['rules' => 'Добавьте хотя бы одну волну']);
        }

        return ['name' => $data['name'], 'rules' => $rules];
    }
}
