<?php

namespace App\Http\Logistics;

use App\Http\Cabinet\InviteController;
use App\Logistics\Transport;
use App\Users\Actions\AddPerson;
use App\Users\Actions\DecideAccess;
use App\Users\Actions\IssueInvite;
use App\Users\Actions\NewPassword;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Исполнители логиста (17.10.2026, владелец: «у Татьяны свои исполнители, должна быть возможность заводить их в систему и
 * назначать»): люди строкой (город, пункт СДЭК — туда Лиза шлёт документы), ссылки-приглашения. Логисту — свои, админу —
 * все (с логистом). Отключить — доступ закрыт целиком (`DecideAccess::reject`): других ролей у исполнителя нет.
 */
class ContractorController
{
    public const PILLS = ['people' => 'Исполнители', 'invites' => 'Ссылки'];

    public function index(Request $request)
    {
        $me = $request->user();
        $pill = array_key_exists($request->query('preset', ''), self::PILLS) ? $request->query('preset') : 'people';
        // Сколько у исполнителя открытых вывозов — логисту видно, кто свободен (18.10.2026).
        $people = User::contractorsFor($me)->whereNull('rejected_at')->with('logist')->orderBy('name')
            ->select('users.*')->addSelect(['busy' => Transport::open()->selectRaw('count(*)')->whereColumn('transports.executor_id', 'users.id')])
            ->get();
        $invites = Invite::ofLogist($me)->with(['creator', 'buyers'])->latest()->get();

        return view('logistics.contractors.index', [
            'pill' => $pill, 'pills' => self::PILLS,
            'counts' => ['people' => $people->count(), 'invites' => $invites->filter->isActive()->count()],
            'people' => $people, 'invites' => $invites, 'fresh' => session('invite'),
        ]);
    }

    /** Ссылка исполнителю — одноразовая на неделю, подпись — кому (имя). */
    public function invite(Request $request, IssueInvite $issue)
    {
        abort_unless($request->user()->isLogist() || $request->user()->isAdmin(), 404);
        $invite = $issue->contractor($request->user(), $request->validate(['label' => ['nullable', 'string', 'max:120']])['label'] ?? null);

        return redirect(InviteController::home())->with('invite', $invite->id);
    }

    /**
     * Исполнитель сразу, без ссылки (18.10.2026, владелец: «исполнителя вывоза надо создавать не по ссылке, а сразу ввести
     * его данные»): та же ссылка исполнителю (`IssueInvite::contractor`) и тут же регистрация по ней (`AddPerson`) — её
     * человек, вход по телефону и паролю; пароль — шторкой входа. Страница задачи по ссылке (`/trip/…`) работает и без входа.
     */
    public function store(Request $request, IssueInvite $issue, AddPerson $add)
    {
        $me = $request->user();
        abort_unless($me->isLogist(), 404);
        $data = $request->validate(AddPerson::rules() + self::FIELDS);
        [$user, $credentials] = $add(fn () => $issue->contractor($me, $data['first_name']), $data, array_filter([
            'logistics_city' => $data['logistics_city'] ?? null, 'cdek_point' => $data['cdek_point'] ?? null,
        ]));

        // Из формы заявки — обратно в неё, и новый исполнитель уже выбран (черновик формы не перебьёт: значение с сервера).
        return $request->input('back') === 'order'
            ? redirect(url()->previous())->withInput(['executor_id' => $user->id])->with('credentials', $credentials)
            : redirect('/contractors')->with('credentials', $credentials);
    }

    /** Новый пароль своему исполнителю — шторка входа, как после «Добавить». */
    public function password(Request $request, User $user, NewPassword $new)
    {
        $this->guard($request, $user);

        return back()->with('credentials', $new($user));
    }

    private const FIELDS = ['logistics_city' => ['nullable', 'string', 'max:120'], 'cdek_point' => ['nullable', 'string', 'max:300']];

    /** Город и пункт СДЭК исполнителя. */
    public function update(Request $request, User $user)
    {
        $this->guard($request, $user);
        $user->update($request->validate(self::FIELDS));

        return back()->with('toast', 'Сохранено');
    }

    public function revoke(Request $request, User $user, DecideAccess $decide)
    {
        $this->guard($request, $user);
        $decide->reject($user, $request->user());

        return redirect('/contractors')->with('toast', $user->shortName().': доступ закрыт');
    }

    /** Только исполнитель этого логиста (админу — любой исполнитель). */
    private function guard(Request $request, User $user): void
    {
        $me = $request->user();
        abort_unless($user->hasRole(Role::Contractor) && ($me->isAdmin() || $user->logist_id === $me->id), 404);
    }
}
