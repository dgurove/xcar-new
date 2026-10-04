<?php

namespace App\Http\Admin;

use App\Offers\Actions\DeleteDraft;
use App\Offers\Offer;
use Illuminate\Http\Request;

/** «Удалить черновик» в редакторе: модератору — свои, админу — любые, пока черновик не выходил наружу. */
class OfferDraftController
{
    public function destroy(Request $request, Offer $offer, DeleteDraft $delete)
    {
        abort_unless($offer->isDeletableBy($request->user()), 403);
        $delete($offer, $request->user());
        session()->forget("mail-draft.{$offer->id}");

        return redirect('/')->with('toast', 'Черновик удалён');
    }
}
