<?php

namespace App\Http\Admin;

use App\Offers\Actions\MarkInterest;
use App\Offers\Interest;
use App\Offers\InterestState;
use Illuminate\Http\Request;

class InterestController
{
    public function update(Request $request, Interest $interest, MarkInterest $mark)
    {
        $state = InterestState::from($request->validate(['state' => ['required', 'string']])['state']);
        $mark($interest, $state, $request->user());

        return back()->with('toast', $state->label());
    }
}
