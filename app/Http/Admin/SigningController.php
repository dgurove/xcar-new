<?php

namespace App\Http\Admin;

use App\Offers\Actions\ConfirmSigning;
use App\Offers\Actions\OfferSigningSlots;
use App\Offers\Actions\PickSigningTime;
use App\Offers\Actions\SaveSigningWish;
use App\Offers\Deal;
use App\Offers\Signing;
use App\Support\Ago;
use App\Workflow\Outcome;
use Illuminate\Http\Request;

/** Запись на подписание в CRM (`Offers\Signing`): свободное время поставщика и время за менеджера. */
final class SigningController
{
    public function slots(Request $request, Deal $deal, OfferSigningSlots $offer)
    {
        $data = $request->validate([
            'exit' => ['required', 'integer'],
            'slots' => ['required', 'array', 'max:12'],
            'slots.*' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        $deal = $offer($deal, Outcome::findOrFail($data['exit']), $request->user(), $data['slots'], $data['address'] ?? null);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Время показано менеджеру: '.count($deal->signing_slots ?? []));
    }

    /** «Запись подтверждена» — на какое время записал поставщик (`ConfirmSigning`). */
    public function confirm(Request $request, Deal $deal, ConfirmSigning $confirm)
    {
        $data = $request->validate(['exit' => ['required', 'integer'], 'at' => ['nullable', 'string', 'max:16'], 'other' => ['nullable', 'string', 'max:20']]);
        $at = trim((string) ($data['other'] ?? '')) ?: trim((string) ($data['at'] ?? ''));
        $deal = $confirm($deal, Outcome::findOrFail($data['exit']), $request->user(), $at);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Записали на '.Signing::short($deal->signing_at));
    }

    public function pick(Request $request, Deal $deal, PickSigningTime $pick)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $deal = $pick($deal, $request->user(), (string) $request->validate(['at' => ['required', 'string', 'max:30']])['at'], staff: true);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Записываем на '.Ago::at($deal->signing_at, true, words: false));
    }

    /** День и время за менеджера (`SaveSigningWish`). */
    public function wish(Request $request, Deal $deal, SaveSigningWish $save)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $data = $request->validate(['slots' => ['required', 'array', 'max:60'], 'slots.*' => ['string', 'max:16']], ['slots.required' => 'Отметьте хотя бы одно время']);
        $deal = $save($deal, $request->user(), $data['slots'], staff: true);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Покупатель может '.Signing::wishLine($deal));
    }

    public function ics(Deal $deal)
    {
        abort_unless(Signing::booked($deal), 404);

        return response(Signing::ics($deal), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="podpisanie-'.$deal->offer->number.'.ics"']);
    }
}
