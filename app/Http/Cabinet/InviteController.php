<?php

namespace App\Http\Cabinet;

use App\Users\Invite;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Пригласительные ссылки менеджера: что покупатель укажет, в какую группу попадёт. */
class InviteController
{
    public function index(Request $request)
    {
        $me = $request->user();

        return view('cabinet.buyers.invites', [
            'invites' => $me->invites()->with('group')->withCount('buyers')->latest()->get(),
            'groups' => $me->ownGroups,
            'fresh' => session('invite'),
        ]);
    }

    public function store(Request $request)
    {
        $me = $request->user();
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'group_id' => ['nullable', Rule::exists('buyer_groups', 'id')->where('manager_id', $me->id)],
        ]);
        $invite = $me->invites()->create([
            'code' => Invite::freshCode(),
            'created_by' => $me->id,
            'label' => trim((string) $data['label']) ?: null,
            'group_id' => $data['group_id'] ?: null,
            'fields' => ['phone' => $request->boolean('phone'), 'email' => $request->boolean('email')],
        ]);

        return redirect('/lk/pokupateli/priglasheniya')->with('invite', $invite->id);
    }

    public function disable(Request $request, Invite $invite)
    {
        abort_unless($invite->manager_id === $request->user()->id, 404);
        $invite->update(['disabled_at' => now()]);

        return back()->with('toast', 'Ссылка выключена');
    }

    public function enable(Request $request, Invite $invite)
    {
        abort_unless($invite->manager_id === $request->user()->id, 404);
        $invite->update(['disabled_at' => null]);

        return back()->with('toast', 'Ссылка снова действует');
    }
}
