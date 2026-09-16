<?php

namespace App\Http\Cabinet;

use App\Users\Actions\IssueAdminInvite;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;

/** Приглашения админа в кабинете на сайте: как у менеджера, но менеджеру и покупателю от имени менеджера. */
class AdminInviteController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);

        return view('cabinet.invites', [
            'invites' => Invite::with(['manager', 'creator', 'group', 'buyers'])->withCount('buyers')->latest()->get(),
            'managers' => User::where('role', Role::Manager)->orderBy('name')->get(),
            'fresh' => session('invite'),
        ]);
    }

    public function store(Request $request, IssueAdminInvite $issue)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $data = $request->validate(IssueAdminInvite::rules());
        $invite = $issue($request->user(), $data, $request->boolean('phone'), $request->boolean('email'));

        return redirect('/lk/priglasheniya')->with('invite', $invite->id);
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
