<?php

namespace App\Http\Logistics;

use App\Http\Cabinet\InviteController;
use App\Logistics\Transport;
use App\Users\Actions\DecideAccess;
use App\Users\Actions\IssueInvite;
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

    /** Город и пункт СДЭК исполнителя. */
    public function update(Request $request, User $user)
    {
        $this->guard($request, $user);
        $user->update($request->validate(['logistics_city' => ['nullable', 'string', 'max:120'], 'cdek_point' => ['nullable', 'string', 'max:300']]));

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
