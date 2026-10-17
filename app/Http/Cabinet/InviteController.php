<?php

namespace App\Http\Cabinet;

use App\Support\Surface;
use App\Users\Actions\AddPerson;
use App\Users\Actions\IssueInvite;
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
            'managers' => $me->isAdmin() ? User::withRole(Role::Manager)->orderBy('name')->get() : collect(),
            'groups' => $me->isAdmin() ? collect() : $me->ownGroups,
            'fresh' => session('invite'),
        ]);
    }

    /** Список с тем, что нужно строке и шторке — тот же в CRM. */
    public static function listFor(User $me)
    {
        return Invite::manageableBy($me)->with(['manager', 'creator', 'group', 'buyers'])->latest()->get();
    }

    /**
     * «Ссылкой» — ссылка; «Ввести данные» (`mode=person`, 18.10.2026) — тот же выбор роли и групп, но человек заводится сразу
     * (`AddPerson`), а пароль показывает шторка входа.
     */
    public function store(Request $request, IssueInvite $issue, AddPerson $add)
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $me->isManager(), 404);
        $person = $request->input('mode') === 'person';
        if ($person) {
            // Срок ссылке, которой человек «пришёл», не нужен: она использована тут же.
            $request->merge(['expires_at' => now()->addDay()->format('Y-m-d H:i')]);
        }
        $data = $request->validate(IssueInvite::rules($me) + ($person ? AddPerson::rules() : []));
        $data['phone'] = $request->boolean('phone');
        $data['email'] = $request->boolean('email');
        if ($person) {
            [$user, $credentials] = $add(fn () => $issue($me, $data), $data);

            return redirect(self::peopleHome($user))->with('credentials', $credentials);
        }
        $invite = $issue($me, $data);

        return redirect(self::home())->with('invite', $invite->id);
    }

    /** Куда после «Добавить»: в CRM — список людей, менеджеру — его покупатели. */
    private static function peopleHome(User $user): string
    {
        return match (Surface::current()) {
            Surface::Crm => '/settings/users',
            default => $user->isBuyer() && $user->manager_id === auth()->id() ? '/buyers/'.$user->id : self::site(),
        };
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

    /** Куда возвращать после создания: на том же хосте, где сделали (на парковке — её «Пользователи»). */
    public static function home(): string
    {
        return match (Surface::current()) {
            Surface::Crm => '/settings/users?preset=invites', Surface::Park => '/users?preset=invites', Surface::Logistics => '/contractors?preset=invites', default => self::site()
        };
    }

    /** Путь для off/on на текущем хосте. */
    public static function base(): string
    {
        return match (Surface::current()) {
            Surface::Crm => '/settings/users/invites', Surface::Park => '/users/invites', Surface::Logistics => '/contractors/invites', default => self::site()
        };
    }

    /** На сайте менеджеру — в «Сделках → Покупатели» (11.10.2026), админу — в кабинете. */
    private static function site(): string
    {
        return request()->is('buyers/invites*') ? '/buyers/invites' : '/account/invites';
    }
}
