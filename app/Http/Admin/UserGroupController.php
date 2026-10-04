<?php

namespace App\Http\Admin;

use App\Offers\Actions\SyncViewers;
use App\Users\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Группы в «Пользователях» (02.10.2026, вместо раздела «Кому показывать»): группы менеджеров — в пилюле «Менеджеры»,
 * модераторов — в «Сотрудниках». В группу берутся только люди её вида. После правки группы менеджеров волны показа
 * опубликованных предложений пересчитываются (`SyncViewers::all`).
 */
class UserGroupController
{
    public function store(Request $request, SyncViewers $sync)
    {
        $kind = $request->validate(['kind' => ['required', Rule::in([UserGroup::MANAGERS, UserGroup::MODERATORS])]])['kind'];
        $data = $this->data($request, $kind);
        $group = UserGroup::create(['name' => $data['name'], 'kind' => $kind, 'position' => (int) UserGroup::where('kind', $kind)->max('position') + 1]);
        $group->members()->sync($data['members'] ?? []);
        $this->synced($group, $sync);

        return back()->with('toast', 'Группа добавлена');
    }

    public function update(Request $request, UserGroup $group, SyncViewers $sync)
    {
        $data = $this->data($request, $group->kind);
        $group->update(['name' => $data['name']]);
        $group->members()->sync($data['members'] ?? []);
        $this->synced($group, $sync);

        return back()->with('toast', 'Сохранено');
    }

    public function destroy(UserGroup $group, SyncViewers $sync)
    {
        $group->delete();
        $this->synced($group, $sync);

        return back()->with('toast', 'Группа удалена');
    }

    private function data(Request $request, string $kind): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'members' => ['nullable', 'array'],
            'members.*' => [Rule::exists('users', 'id')->where(fn ($q) => $q->whereJsonContains('roles', UserGroup::roleOf($kind)->value))],
        ]);
    }

    /** Состав групп менеджеров меняет, кто когда видит предложения; группы модераторов волн не касаются. */
    private function synced(UserGroup $group, SyncViewers $sync): void
    {
        if ($group->kind === UserGroup::MANAGERS) {
            $sync->all();
        }
    }
}
