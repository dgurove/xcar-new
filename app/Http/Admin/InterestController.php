<?php

namespace App\Http\Admin;

use App\Offers\Actions\MarkInterest;
use App\Offers\Actions\RegisterInterest;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InterestController
{
    public function update(Request $request, Interest $interest, MarkInterest $mark)
    {
        $state = InterestState::from($request->validate(['state' => ['required', 'string']])['state']);
        $mark($interest, $state, $request->user());

        return back()->with('toast', $state->label());
    }

    /**
     * Интерес за менеджера или на себя (13.10.2026, владелец): менеджер сказал устно — встаёт в тот же список с «внёс …»,
     * как подтверждение «+ За менеджера».
     */
    public function store(Request $request, Offer $offer, RegisterInterest $register)
    {
        $data = $request->validate([
            'who' => ['required', 'in:manager,me'],
            'manager_id' => ['nullable', 'required_if:who,manager', 'integer', 'exists:users,id'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);
        $me = $data['who'] === 'me';
        $user = $me ? $request->user() : User::findOrFail($data['manager_id']);
        if (! $me && ! $user->isManager()) {
            throw ValidationException::withMessages(['manager_id' => 'Это не менеджер']);
        }
        $register($offer, $user, trim((string) ($data['comment'] ?? '')) ?: null, $me ? null : $request->user());

        return back()->with('toast', $me ? 'Интерес отмечен' : 'Интерес '.$user->shortName().' внесён');
    }
}
