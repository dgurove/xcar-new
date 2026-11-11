<?php

namespace App\Http\Admin;

use App\Offers\Actions\OfferSigningSlots;
use App\Offers\Actions\PickSigningTime;
use App\Offers\Deal;
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

    public function pick(Request $request, Deal $deal, PickSigningTime $pick)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $deal = $pick($deal, $request->user(), (string) $request->validate(['at' => ['required', 'string', 'max:30']])['at'], staff: true);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Записываем на '.Ago::at($deal->signing_at, true, words: false));
    }

    /** Дни покупателя за менеджера (`SaveSigningWish`). */
    public function wish(Request $request, Deal $deal, \App\Offers\Actions\SaveSigningWish $save)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $data = $request->validate(['days' => ['required', 'array', 'max:14'], 'days.*' => ['string', 'max:10'], 'part' => ['nullable', 'string', 'max:20']],
            ['days.required' => 'Отметьте хотя бы один день']);
        $deal = $save($deal, $request->user(), $data['days'], $data['part'] ?? 'any');

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Покупатель может '.\App\Offers\Signing::wishLine($deal));
    }

    public function ics(Deal $deal)
    {
        abort_unless(\App\Offers\Signing::booked($deal), 404);

        return response(\App\Offers\Signing::ics($deal), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="podpisanie-'.$deal->offer->number.'.ics"']);
    }
}
