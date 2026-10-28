<?php

namespace App\Http\Admin;

use App\Offers\Actions\ApplyReprice;
use App\Offers\Actions\DismissReprice;
use App\Offers\Offer;
use App\Offers\Reprice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Плашка и шторка «Переоценка» (`x-offer.reprice`, 28.10.2026): применить с решением по каждому подтвердившему или отклонить. */
class RepriceController
{
    public function apply(Request $request, Offer $offer, Reprice $reprice, ApplyReprice $apply)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        $request->merge(array_map(fn ($v) => blank($v) ? null : Money::rubles($v), $request->only(['price', 'publish_price', 'asking_price'])));
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:1'],
            'publish_price' => ['nullable', 'integer', 'min:1'],
            'asking_price' => ['nullable', 'integer', 'min:1'],
            'bids' => ['array'],
            'bids.*' => [Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE])],
            'deal' => ['nullable', Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE])],
        ], ['price.required' => 'Новая закупочная']);
        $prices = $offer->state === \App\Offers\OfferState::Open ? array_filter(array_intersect_key($data, array_flip(['asking_price']))) : [];
        $apply($reprice, $request->user(), $data['price'], $data['bids'] ?? [], $data['deal'] ?? null, $prices);

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка применена');
    }

    /** Ответ за менеджера про выросшую закупочную (28.10.2026): он сказал устно. */
    public function answer(Request $request, Offer $offer, \App\Offers\RepriceAsk $ask, \App\Offers\Actions\AnswerReprice $answer)
    {
        abort_unless($ask->offer_id === $offer->id, 404);
        $agree = $request->boolean('agree');
        $answer($ask, $agree, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', $agree ? 'За менеджера: согласен' : 'За менеджера: отказался');
    }

    public function dismiss(Request $request, Offer $offer, Reprice $reprice, DismissReprice $dismiss)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        $dismiss($reprice->setRelation('offer', $offer), $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка отклонена');
    }
}
