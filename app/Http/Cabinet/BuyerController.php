<?php

namespace App\Http\Cabinet;

use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Users\Actions\IssuePasswordLink;
use App\Users\BuyerGroup;
use App\Users\User;
use Illuminate\Http\Request;

/** Покупатели менеджера: список с группами-пилюлями и страница человека. */
class BuyerController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $groups = $me->ownGroups()->withCount('members')->get();
        $group = $request->integer('group') ?: null;
        $q = $me->buyers()->with('groups')->orderBy('name');
        if ($group) {
            $q->whereHas('groups', fn ($g) => $g->where('buyer_groups.id', $group));
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('login', 'ilike', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $term).'%'));
        }
        $buyers = $q->paginate(50)->withQueryString();

        // Числа на строках — одним проходом: сколько машин видит и сколько интересов в работе.
        $ids = $buyers->pluck('id')->all();
        $seen = collect($ids)->mapWithKeys(fn ($id) => [$id => 0]);
        foreach ($buyers as $buyer) {
            $seen[$buyer->id] = Offer::where('state', OfferState::Open)->visibleTo($buyer)->count();
        }
        $interests = Interest::whereIn('user_id', $ids)->where('state', InterestState::New)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        return view('cabinet.buyers.index', [
            'buyers' => $buyers,
            'groups' => $groups,
            'group' => $group,
            'pills' => ['' => 'Все'] + $groups->mapWithKeys(fn ($g) => [(string) $g->id => $g->name])->all(),
            'counts' => ['' => $me->buyers()->count()] + $groups->mapWithKeys(fn ($g) => [(string) $g->id => $g->members_count])->all(),
            'seen' => $seen,
            'interests' => $interests,
        ]);
    }

    public function show(Request $request, User $user)
    {
        $me = $request->user();
        abort_unless($user->manager_id === $me->id && $user->isBuyer(), 404);
        $user->load('groups');

        $direct = Showing::where('manager_id', $me->id)->where('user_id', $user->id)->pluck('offer_id')->all();
        $offers = Offer::with(['brand', 'model', 'settlement', 'media', 'favorites'])->where('state', OfferState::Open)->visibleTo($user)->orderByDesc('published_at')->get();
        // Через какую группу пришёл показ — чипом на карточке; прямой показ снимается здесь же.
        $via = Showing::where('manager_id', $me->id)->whereIn('offer_id', $offers->pluck('id'))->whereIn('group_id', $user->groups->pluck('id'))->with('group')->get()->groupBy('offer_id');

        return view('cabinet.buyers.show', [
            'buyer' => $user,
            'groups' => $me->ownGroups,
            'offers' => $offers,
            'direct' => $direct,
            'via' => $via,
            'interests' => $user->interests()->with('offer.brand', 'offer.model')->latest()->get(),
            'link' => session('password_link'),
        ]);
    }

    /** В каких группах покупатель — чипы с автосохранением. */
    public function groups(Request $request, User $user)
    {
        $me = $request->user();
        abort_unless($user->manager_id === $me->id, 404);
        $ids = collect($request->input('groups', []))->map(fn ($v) => (int) $v)->all();
        $allowed = BuyerGroup::whereIn('id', $ids)->where('manager_id', $me->id)->pluck('id')->all();
        $user->groups()->sync(collect($allowed)->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all());

        return back()->with('toast', 'Группы сохранены');
    }

    public function passwordLink(Request $request, User $user, IssuePasswordLink $issue)
    {
        $me = $request->user();
        abort_unless($user->manager_id === $me->id, 404);

        return back()->with('password_link', ['user' => $user->id, 'url' => $issue($user, $me)]);
    }
}
