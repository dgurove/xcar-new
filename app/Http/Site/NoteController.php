<?php

namespace App\Http\Site;

use App\Offers\Offer;
use App\Offers\OfferNote;
use App\Users\Impersonation;
use Illuminate\Http\Request;

/** Личная заметка к ТС (18.10.2026): поле пишет само, ответ пустой. Под «Войти как» — только чтение. */
final class NoteController
{
    public function update(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($user->keepsNotes() && $offer->isVisibleTo($user), 404);
        abort_if(Impersonation::active(), 403);
        $text = (string) $request->validate(['text' => ['nullable', 'string', 'max:'.OfferNote::MAX]])['text'];
        OfferNote::put($user, $offer, $text);

        return response()->noContent();
    }
}
