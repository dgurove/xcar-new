{{-- Закупочная выросла — вопрос менеджеру (28.10.2026, `RepriceAsk`): тем же видом, что контрпредложение (`counter-card`);
     что поменялось — тем, что он видит (`RepriceAsk::shownAs`: цена «от», закупочная ДКП и гаража, вознаграждение ПРАЙМ),
     первым в задаче сделки, гаража и над «Ваше подтверждение». «Согласен» — новая цена встаёт (договор и счета сделки
     перевыставятся), «Отказываюсь» — сделка отменяется / подтверждение снимается, с переспросом. Только его вопрос. --}}
@props(['deal' => null, 'bid' => null])
@php
    $me = auth()->user();
    $ask = $me ? \App\Offers\RepriceAsk::pending()->where('user_id', $me->id)
        ->when($deal, fn ($q) => $q->where('deal_id', $deal->id), fn ($q) => $q->where('bid_id', $bid?->id ?? 0))
        ->with(['deal', 'bid'])->latest('id')->first() : null;
    $ask = $ask?->alive() ? $ask : null;
@endphp
@if ($ask)
    <x-offer.answer-card {{ $attributes }} :caption="$ask->shownAs() === 'reward' ? 'Вознаграждение уменьшится' : 'Новая цена от поставщика'"
        :sum="$ask->shown_to" :sub="mb_strtolower($ask->shownLabel()).' '.($ask->shownAs() === 'reward' ? 'было' : 'была').' '.\App\Support\Money::rub((int) $ask->shown_from)"
        :due="$ask->due_at" :action="'/reprice-asks/'.$ask->id" decline="Отказываюсь"
        agree-ask="Согласиться на новую цену?" :agree-text="$ask->deal_id ? 'Счёт и договор обновятся' : 'Ваше подтверждение останется'"
        decline-ask="Отказаться?" :decline-text="$ask->declineMeans()" error="reprice"/>
@endif
