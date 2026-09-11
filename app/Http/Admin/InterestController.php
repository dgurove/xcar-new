<?php

namespace App\Http\Admin;

use App\Offers\Interest;
use App\Offers\InterestState;
use Illuminate\Http\Request;

class InterestController
{
    public function update(Request $request, Interest $interest)
    {
        $state = InterestState::from($request->validate(['state' => ['required', 'string']])['state']);
        $interest->update(['state' => $state]);

        return back()->with('toast', $state->label());
    }
}
