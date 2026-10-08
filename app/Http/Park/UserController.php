<?php

namespace App\Http\Park;

use App\Http\Cabinet\InviteController;
use App\Park\City;
use App\Support\Nav;
use App\Users\Actions\DecideAccess;
use App\Users\Actions\IssueInvite;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Люди парковки — администратору парковки (владелец 08.10.2026): администраторы парковки, управляющие и помощники;
 * роль меняется тут же, доступ закрывается кнопкой. Заводят управляющих и помощников пригласительной ссылкой — пилюля
 * «Ссылки». Админ CRM без роли «Администратор парковки» сюда не входит, а CRM роли парковки не правит.
 */
class UserController
{
    public const PILLS = ['people' => 'Люди', 'invites' => 'Ссылки'];

    /** Роль на парковке словом — одна на человека: админ парковки, управляющий или помощник. */
    public const KINDS = ['admin' => 'Администратор парковки', 'manager' => 'Управляющий', 'helper' => 'Помощник'];

    public function index(Request $request)
    {
        $pill = array_key_exists($request->query('preset', ''), self::PILLS) ? $request->query('preset') : 'people';
        $people = User::withRole(Role::ParkAdmin, Role::Parking)->whereNotNull('approved_at')->orderBy('name')->get();
        $invites = Invite::ofPark()->with(['creator', 'buyers'])->latest()->get();

        return view('park.users.index', [
            'pill' => $pill, 'pills' => self::PILLS,
            'counts' => ['people' => $people->count(), 'invites' => $invites->filter->isActive()->count()],
            'people' => $people, 'invites' => $invites, 'fresh' => session('invite'),
        ]);
    }

    /** Роль на парковке: администратор, управляющий или помощник; прочие роли человека (CRM, сайт) не трогаются. */
    public function update(Request $request, User $user)
    {
        $this->guard($request, $user);
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(self::KINDS))], 'park_city_id' => self::cityRule()]);
        $kind = $data['kind'];
        $roles = ($user->roles ?? collect())->reject(fn (Role $r) => in_array($r, [Role::ParkAdmin, Role::Parking], true))->values()->all();
        $roles[] = $kind === 'admin' ? Role::ParkAdmin : Role::Parking;
        $user->update(['roles' => $roles, 'park_readonly' => $kind === 'helper', 'park_city_id' => $kind === 'admin' ? null : ($data['park_city_id'] ?? null)]);
        Nav::forgetStaffCounts();

        return back()->with('toast', self::KINDS[$kind]);
    }

    /** Закрыть доступ к парковке: роли парковки снимаются; если других нет — человек закрыт целиком, как в CRM. */
    public function revoke(Request $request, User $user, DecideAccess $decide)
    {
        $this->guard($request, $user);
        $roles = ($user->roles ?? collect())->reject(fn (Role $r) => in_array($r, [Role::ParkAdmin, Role::Parking], true))->values()->all();
        if ($roles === []) {
            $decide->reject($user, $request->user());
        } else {
            $user->update(['roles' => $roles, 'park_readonly' => false]);
        }

        return redirect('/users')->with('toast', 'Доступ к парковке закрыт');
    }

    /** Ссылка управляющему или помощнику: одноразовая, со сроком; строка ссылки — общий `invites.row`. */
    public function invite(Request $request, IssueInvite $issue)
    {
        $data = $request->validate(['expires_at' => ['required', 'date', 'after:now'], 'park_city_id' => self::cityRule()]);
        $invite = $issue->park($request->user(), Carbon::parse($data['expires_at']), $request->boolean('park_readonly'), isset($data['park_city_id']) ? (int) $data['park_city_id'] : null);

        return redirect(InviteController::home())->with('invite', $invite->id);
    }

    /** Город управляющего — один из городов парковок; пусто — весь парк. */
    private static function cityRule(): array
    {
        return ['nullable', 'integer', Rule::in(array_keys(City::options()))];
    }

    /** Только люди парковки и не сам себя: свою роль администратор не снимет — иначе парковкой некому управлять. */
    private function guard(Request $request, User $user): void
    {
        abort_unless($user->hasRole(Role::ParkAdmin, Role::Parking) && ! $user->is($request->user()), 404);
    }
}
