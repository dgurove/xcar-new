<?php

namespace App\Http\Cabinet;

use App\Live\Stream;
use App\Offers\Actions\MarkInterest;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Support\Sort;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Интерес покупателей менеджера: новые сверху, «Связались» смахиванием. */
class BuyerInterestController
{
    public const SORTS = ['fresh' => ['Дата', 'desc'], 'buyer' => ['Покупатель', 'asc']];

    public function index(Request $request)
    {
        $me = $request->user();
        $preset = $request->query('preset', 'new');
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        $q = Interest::ofManager($me)->with(['user', ...User::withAvatar('user.media'), 'offer', 'offer.media'])
            ->when($sort->key === 'buyer', fn ($i) => $i->orderBy(User::select('name')->whereColumn('users.id', 'interests.user_id'), $sort->dir()))
            ->orderBy('interests.created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('interests.id');
        if ($preset === 'new') {
            $q->where('state', InterestState::New);
        }

        $interests = $q->paginate(50)->withQueryString();

        return view('cabinet.buyers.interests', [
            'interests' => $interests,
            'visible' => Offer::visibleIdsTo($me, $interests->getCollection()->pluck('offer')),
            'preset' => $preset,
            'sort' => $sort,
            'counts' => ['new' => Interest::ofManager($me)->where('state', InterestState::New)->count()],
        ]);
    }

    public function update(Request $request, Interest $interest, MarkInterest $mark)
    {
        $me = $request->user();
        abort_unless($interest->user->manager_id === $me->id, 404);
        $state = InterestState::from($request->validate(['state' => ['required', Rule::enum(InterestState::class)]])['state']);
        $mark($interest, $state, $me);

        // Смахнули строку в списке — ответ той же строкой; с кнопки на странице — назад.
        if (str_contains((string) $request->header('Accept'), Stream::TYPE) && $request->boolean('row')) {
            return Stream::view('cabinet.buyers.interest-row-stream', ['interest' => $interest->fresh(['user', 'offer', 'offer.media'])]);
        }

        return back()->with('toast', $state->label());
    }
}
