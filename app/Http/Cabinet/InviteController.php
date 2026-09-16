<?php

namespace App\Http\Cabinet;

use App\Support\Surface;
use App\Users\Actions\IssueInvite;
use App\Users\BuyerGroup;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Пригласительные ссылки — один экран и одна дверь для менеджера и админа,
 * в кабинете на сайте и в CRM («Пользователи → Ссылки»): менеджер видит ссылки
 * своих покупателей, в том числе сделанные для него админом; админ — все.
 */
class InviteController
{
    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $me->isManager(), 404);

        return view('invites.index', [
            'invites' => self::listFor($me),
            'admin' => $me->isAdmin(),
            'managers' => $me->isAdmin() ? User::where('role', Role::Manager)->orderBy('name')->get() : collect(),
            'groups' => $me->isAdmin() ? collect() : $me->ownGroups,
            'fresh' => session('invite'),
        ]);
    }

    /** Список с тем, что нужно строке и шторке — тот же в CRM. */
    public static function listFor(User $me)
    {
        return Invite::manageableBy($me)->with(['manager', 'creator', 'group', 'buyers'])->latest()->get();
    }

    public function store(Request $request, IssueInvite $issue)
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $me->isManager(), 404);
        $data = $request->validate(IssueInvite::rules($me));
        $data['phone'] = $request->boolean('phone');
        $data['email'] = $request->boolean('email');
        $invite = $issue($me, $data);

        return redirect(self::home())->with('invite', $invite->id);
    }

    public function disable(Request $request, Invite $invite)
    {
        abort_unless($invite->isManageableBy($request->user()), 404);
        $invite->update(['disabled_at' => now()]);

        return back()->with('toast', 'Ссылка выключена');
    }

    public function enable(Request $request, Invite $invite)
    {
        abort_unless($invite->isManageableBy($request->user()), 404);
        $invite->update(['disabled_at' => null]);

        return back()->with('toast', 'Ссылка снова действует');
    }

    /** Куда возвращать после создания: на том же хосте, где сделали. */
    public static function home(): string
    {
        return Surface::current() === Surface::Crm ? '/settings/users?preset=invites' : '/account/invites';
    }

    /** Путь для off/on на текущем хосте. */
    public static function base(): string
    {
        return Surface::current() === Surface::Crm ? '/settings/users/invites' : '/account/invites';
    }
}
