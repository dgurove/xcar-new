<?php

namespace App\Http\Cabinet;

use App\Chats\Chat;
use App\Chats\Message;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Users\Actions\IssuePasswordLink;
use App\Users\BuyerGroup;
use App\Users\User;
use Illuminate\Http\Request;

/** Покупатели менеджера: группы и люди одним списком, страница человека. */
class BuyerController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $term = trim((string) $request->query('q'));
        $q = $me->buyers()->with('groups')->orderBy('name');
        if ($term) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('login', 'ilike', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $term).'%'));
        }
        $buyers = $q->paginate(50)->withQueryString();

        // Группы — свои объекты над списком (при поиске не показываются: ищут людей); на строке —
        // сколько человек и сколько предложений открыто группе.
        $groups = $term ? collect() : $me->ownGroups()->withCount('members')->get();
        $groupSeen = $groups->isEmpty() ? collect() : Showing::whereIn('group_id', $groups->pluck('id'))
            ->whereHas('offer', fn ($o) => $o->where('state', OfferState::Open))
            ->selectRaw('group_id, count(*) as n')->groupBy('group_id')->pluck('n', 'group_id');

        // Числа на строках — одним проходом: сколько машин видит и сколько интересов в работе.
        $ids = $buyers->pluck('id')->all();
        $seen = collect($ids)->mapWithKeys(fn ($id) => [$id => 0]);
        foreach ($buyers as $buyer) {
            $seen[$buyer->id] = Offer::where('state', OfferState::Open)->visibleTo($buyer)->count();
        }
        $interests = Interest::whereIn('user_id', $ids)->where('state', InterestState::New)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        // Строка «Интерес» над списком: новых — лаймом, иначе сколько всего.
        $mine = Interest::whereHas('user', fn ($u) => $u->where('manager_id', $me->id));
        $interestAll = $term ? 0 : (clone $mine)->count();
        $interestNew = $term ? 0 : $mine->where('state', InterestState::New)->count();

        return view('cabinet.buyers.index', [
            'interestNew' => $interestNew,
            'interestAll' => $interestAll,
            'buyers' => $buyers,
            'groups' => $groups,
            'groupSeen' => $groupSeen,
            'term' => $term,
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

        // Пришли из шапки чата — «‹ Чат» вместо «‹ Покупатели».
        $chat = $request->integer('chat') ? Chat::whereKey($request->integer('chat'))->where('user_id', $user->id)->where('manager_id', $me->id)->first() : null;

        return view('cabinet.buyers.show', [
            'back' => $chat ? ['Чат', '/account/chats/'.$chat->id] : null,
            'buyer' => $user,
            'groups' => $me->ownGroups,
            'offers' => $offers,
            'direct' => $direct,
            'via' => $via,
            'interests' => $user->interests()->with('offer.brand', 'offer.model')->latest()->get(),
            'chats' => Chat::where('user_id', $user->id)->where('manager_id', $me->id)->with(['offer.brand', 'offer.model'])
                ->addSelect(['*', 'last_text' => Message::select('text')->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1)])->orderByDesc('last_message_at')->get(),
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
