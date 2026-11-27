<?php

namespace App\Http\Admin;

use App\Offers\Offer;
use App\Offers\PlannedLetters;
use Illuminate\Http\Request;

/** Запланированное письмо поставщику (27.11.2026, `Offers\PlannedLetters`): «Отменить письмо» в шаге согласия. */
final class PlannedLetterController
{
    public function destroy(Request $request, Offer $offer, PlannedLetters $letters)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $planned = PlannedLetters::pending($offer->deal);
        if ($planned) {
            $letters->cancel($planned, 'отменили вручную', $request->user());
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', $planned ? 'Письмо отменено' : 'Письмо уже ушло или отменено');
    }
}
