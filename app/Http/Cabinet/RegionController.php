<?php

namespace App\Http\Cabinet;

use App\Cars\Region;
use App\Users\Actions\SetOfferRegions;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * «Регионы» (22.10.2026): откуда показывать предложения. Менеджер и супер-покупатель — себе в кабинете сайта, админ — за
 * человека в CRM из его карточки (`/settings/users/{id}/regions`).
 */
final class RegionController
{
    public function show(Request $request, ?User $user = null)
    {
        $user = $this->whose($request, $user);

        return view('cabinet.regions', [
            'person' => $user,
            'regions' => Region::orderBy('name')->get(['id', 'plate', 'name']),
            'chosen' => $user->offer_regions,
            'action' => $user->is($request->user()) ? '/account/regions' : '/settings/users/'.$user->id.'/regions',
            'back' => $user->is($request->user()) ? null : [$user->name, '/settings/users/'.$user->id],
        ]);
    }

    public function update(Request $request, SetOfferRegions $set, ?User $user = null)
    {
        $user = $this->whose($request, $user);
        $data = $request->validate(['regions' => ['nullable', 'array'], 'regions.*' => ['integer']]);
        $set($user, $data['regions'] ?? []);

        return back()->with('toast', 'Регионы: '.mb_lcfirst(SetOfferRegions::label($user->fresh())));
    }

    /** Себе — менеджеру и супер-покупателю; за другого — только админ CRM. */
    private function whose(Request $request, ?User $user): User
    {
        $me = $request->user();
        if ($user && ! $user->is($me)) {
            abort_unless($me->isAdmin() && ($user->isManager() || $user->isSuperBuyer()), 404);

            return $user;
        }
        abort_unless($me->isManager() || $me->isSuperBuyer(), 404);

        return $me;
    }
}
