<?php

namespace App\Http\Admin;

use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Пригласительные ссылки админа: менеджеру — одноразовая (пришедший сразу
 * менеджер, дальше ссылка мертва), покупателю — от имени выбранного менеджера,
 * как если бы тот сделал её сам.
 */
class InviteController
{
    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $data = $request->validate([
            'role' => ['required', Rule::in([Role::Manager->value, Role::Buyer->value])],
            'label' => ['nullable', 'string', 'max:60'],
            'manager_id' => ['required_if:role,buyer', 'nullable', Rule::exists('users', 'id')->where('role', Role::Manager->value)],
        ]);
        $manager = $data['role'] === Role::Manager->value;
        $invite = Invite::create([
            'code' => Invite::freshCode(),
            'role' => $manager ? Role::Manager : Role::Buyer,
            'created_by' => $request->user()->id,
            'manager_id' => $manager ? null : (int) $data['manager_id'],
            'label' => trim((string) $data['label']) ?: null,
            'max_uses' => $manager ? 1 : null,
            'fields' => $manager ? ['phone' => true, 'email' => true] : ['phone' => $request->boolean('phone'), 'email' => $request->boolean('email')],
        ]);

        return redirect('/nastroyki/polzovateli?preset=invites')->with('invite', $invite->id);
    }

    public function disable(Request $request, Invite $invite)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $invite->update(['disabled_at' => now()]);

        return back()->with('toast', 'Ссылка выключена');
    }

    public function enable(Request $request, Invite $invite)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $invite->update(['disabled_at' => null]);

        return back()->with('toast', 'Ссылка снова действует');
    }
}
