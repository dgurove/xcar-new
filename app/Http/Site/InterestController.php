<?php

namespace App\Http\Site;

use App\Offers\Actions\RegisterInterest;
use App\Offers\Actions\WithdrawInterest;
use App\Offers\Offer;
use Illuminate\Http\Request;

class InterestController
{
    public function store(Request $request, Offer $offer, RegisterInterest $register)
    {
        $user = $request->user();
        abort_unless($user->role->canInterest() && $offer->isVisibleTo($user), 404);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $register($offer, $user, $data['comment'] ?? null);

        return back()->with('toast', $user->isBuyer() && $user->manager ? $user->manager->shortName().' свяжется с вами' : 'Менеджер свяжется с вами');
    }

    /** Покупатель передумал: интерес снимается, у менеджера строка пропадает. */
    public function destroy(Request $request, Offer $offer, WithdrawInterest $withdraw)
    {
        $interest = $offer->interests()->where('user_id', $request->user()->id)->first();
        abort_unless($interest, 404);
        $withdraw($interest, $request->user());

        return back()->with('toast', 'Интерес снят');
    }
}
