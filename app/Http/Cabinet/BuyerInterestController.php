<?php

namespace App\Http\Cabinet;

use App\Live\Stream;
use App\Offers\Actions\MarkInterest;
use App\Offers\Interest;
use App\Offers\InterestState;
use Illuminate\Http\Request;

/** Интерес покупателей менеджера: новые сверху, «Связались» смахиванием. */
class BuyerInterestController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $preset = $request->query('preset', 'new');
        $q = Interest::whereHas('user', fn ($u) => $u->where('manager_id', $me->id))->with(['user', 'offer.brand', 'offer.model', 'offer.media'])->latest();
        if ($preset === 'new') {
            $q->where('state', InterestState::New);
        }

        return view('cabinet.buyers.interests', [
            'interests' => $q->paginate(50)->withQueryString(),
            'preset' => $preset,
            'counts' => ['new' => Interest::whereHas('user', fn ($u) => $u->where('manager_id', $me->id))->where('state', InterestState::New)->count()],
        ]);
    }

    public function update(Request $request, Interest $interest, MarkInterest $mark)
    {
        $me = $request->user();
        abort_unless($interest->user->manager_id === $me->id, 404);
        $state = InterestState::from($request->validate(['state' => ['required', 'string']])['state']);
        $mark($interest, $state, $me);

        // Смахнули строку в списке — ответ той же строкой; с кнопки на странице — назад.
        if (str_contains((string) $request->header('Accept'), Stream::TYPE) && $request->boolean('row')) {
            return Stream::view('cabinet.buyers.interest-row-stream', ['interest' => $interest->fresh(['user', 'offer.brand', 'offer.model', 'offer.media'])]);
        }

        return back()->with('toast', $state->label());
    }
}
