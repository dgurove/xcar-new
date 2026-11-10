<?php

namespace App\Http\Admin;

use App\Offers\Actions\AnswerReprice;
use App\Offers\Actions\ApplyReprice;
use App\Offers\Actions\DismissReprice;
use App\Offers\Actions\ShowDealToAll;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\PriceGuard;
use App\Offers\Reprice;
use App\Offers\RepriceAsk;
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
            'bids.*' => [Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE, ApplyReprice::ASK])],
            'deal' => ['nullable', Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE, ApplyReprice::ASK])],
            'show_all' => ['boolean'],
            'show_days' => ['nullable', 'integer', Rule::in(ShowDealToAll::DAYS)],
        ], ['price.required' => 'Новая закупочная']);
        // Цена продажи — в любом состоянии (04.11.2026, владелец: «старая цена продажи ниже новой закупочной»): в сделке по
        // ней машину увидят все, если показывать, пока менеджер думает.
        $prices = array_filter(array_intersect_key($data, array_flip(['asking_price'])));
        // Открытое: новая закупочная против цены продажи — «Точно?» (`PriceGuard`), её правят тут же в шторке.
        if ($offer->state === OfferState::Open) {
            PriceGuard::assertPrices($prices['asking_price'] ?? $offer->asking_price, $data['price'], $request->boolean('price_ok'));
        }
        $showDays = ($data['deal'] ?? null) === ApplyReprice::ASK && $request->boolean('show_all') ? (int) ($data['show_days'] ?? 7) : null;
        $apply($reprice, $request->user(), $data['price'], $data['bids'] ?? [], $data['deal'] ?? null, $prices, $showDays);

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка применена');
    }

    /**
     * Новая цена после переоценки (10.11.2026): за менеджера (он сказал устно) — цена, «согласен» / «отказался» на наше
     * контрпредложение, отказ; наше решение по названной им цене — принять, контрпредложение, отказать.
     */
    public function answer(Request $request, Offer $offer, RepriceAsk $ask, AnswerReprice $answer)
    {
        abort_unless($ask->offer_id === $offer->id, 404);
        $by = $request->user();
        $do = $request->input('do', $request->has('agree') && ! $request->boolean('agree') ? 'decline' : 'price');
        $rub = fn (string $key) => Money::rubles($request->validate([$key => 'required'])[$key]);
        $offered = $ask->isOffered();
        if ($do === 'accept') {
            $answer->accept($ask, $by);
            $toast = 'Цена принята';
        } elseif ($do === 'counter') {
            $answer->counter($ask, $rub('counter_price'), $by);
            $toast = 'Контрпредложение отправлено';
        } elseif ($do === 'agree') {
            $answer->agree($ask, $by);
            $toast = 'За менеджера: согласен';
        } elseif ($do === 'refuse') {
            $answer->refuseCounter($ask, $by);
            $toast = 'За менеджера: не согласен с контрпредложением';
        } elseif ($do === 'decline') {
            $answer->decline($ask, $by);
            $toast = $offered ? 'Отказали менеджеру' : 'За менеджера: отказался';
        } else {
            $answer->offer($ask, $price = $rub('reprice_price'), $by);
            $toast = 'За менеджера: цена '.Money::rub($price);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', $toast);
    }

    public function stopShow(Request $request, Offer $offer, ShowDealToAll $show)
    {
        $show->stop($offer, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Показ всем снят');
    }

    /** «Ответили не письмом» (шаг «Переоценка», 04.11.2026): поставщику ответили по телефону или из своей почты. */
    public function answered(Request $request, Offer $offer, Reprice $reprice)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        if (! $reprice->answered_at) {
            $reprice->update(['answered_at' => now(), 'answered_by' => $request->user()->id]);
            $offer->log(\App\Offers\OfferEventType::Note, $request->user(), ['text' => 'Поставщику ответили про переоценку не письмом']);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Отмечено: поставщику ответили');
    }

    public function dismiss(Request $request, Offer $offer, Reprice $reprice, DismissReprice $dismiss)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        $dismiss($reprice->setRelation('offer', $offer), $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка отклонена');
    }
}
