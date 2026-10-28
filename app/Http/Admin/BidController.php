<?php

namespace App\Http\Admin;

use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\MakeCounter;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Support\Money;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BidController
{
    /** Подтверждение за менеджера (`PlaceBidFor`): менеджер без интернета — встаёт в список, принимают обычным «Принять». */
    public function storeFor(Request $request, Offer $offer, PlaceBidFor $place)
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['nullable', Rule::enum(BidKind::class)],
            'amount' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);
        $manager = User::findOrFail($data['manager_id']);
        $amount = Money::rubles($data['amount'] ?? null) ?: null;
        $place($offer, $manager, $request->user(), $amount, BidKind::tryFrom($data['kind'] ?? '') ?? BidKind::Buyer, trim((string) ($data['comment'] ?? '')) ?: null);

        return back()->with('toast', 'Подтверждение '.$manager->shortName().' внесено');
    }

    public function decline(Request $request, Bid $bid, DeclineBid $decline)
    {
        $decline($bid, $request->user());

        return back()->with('toast', 'Подтверждение отклонено');
    }

    /** Контрпредложение к подтверждению (26.10.2026): своя цена выше цены менеджера, комментарий по желанию. */
    public function counter(Request $request, Bid $bid, MakeCounter $make)
    {
        $data = $request->validate([
            'counter_amount' => ['required', 'string', 'max:20'],
            'counter_comment' => ['nullable', 'string', 'max:500'],
        ], ['counter_amount.required' => 'Впишите цену']);
        $make($bid, $request->user(), Money::rubles($data['counter_amount']), trim((string) ($data['counter_comment'] ?? '')) ?: null);

        return back()->with('toast', 'Контрпредложение отправлено '.$bid->user->shortName());
    }

    /** Ответ на контрпредложение за менеджера (28.10.2026): он сказал устно — «Согласен» или «Отказался». */
    public function answerCounter(Request $request, Bid $bid, \App\Offers\Actions\AnswerCounter $answer)
    {
        $agree = $request->boolean('agree');
        $answer($bid, $bid->user, $agree, $request->user());

        return back()->with('toast', $agree ? 'За менеджера: согласен, ТС в сделке' : 'За менеджера: отказался');
    }

    public function withdrawCounter(Request $request, Bid $bid, MakeCounter $make)
    {
        $make->withdraw($bid->offer, $bid, $request->user());

        return back()->with('toast', 'Контрпредложение отозвано');
    }
}
