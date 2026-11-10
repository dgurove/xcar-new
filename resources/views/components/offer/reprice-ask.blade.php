{{-- Новая закупочная — вопрос менеджеру (28.10.2026, `RepriceAsk`; с 03.11.2026 в любую сторону, только когда админ выбрал
     «Спросить», ни слова письма), первым в задаче сделки, гаража и над «Ваше подтверждение». Только его вопрос.
     С 10.11.2026 (владелец по 0790/046/09068/26: «где ввод его новой цены подтверждения») ответ — его цена: было → стало
     двумя парами (то, что он видит, `RepriceAsk::shownAs`, и цена продажи), его прежняя цена и поле новой; «Отказываюсь» —
     сделка отменяется / подтверждение снимается, с переспросом. Назвал по сделке — ждёт нашего решения; наше
     контрпредложение — «Согласен» / «Не согласен» (его цена остаётся в силе). --}}
@props(['deal' => null, 'bid' => null])
@php
    use App\Support\{Ago, Money};
    $me = auth()->user();
    $ask = $me ? \App\Offers\RepriceAsk::open()->where('user_id', $me->id)
        ->when($deal, fn ($q) => $q->where('deal_id', $deal->id), fn ($q) => $q->where('bid_id', $bid?->id ?? 0))
        ->with(['deal', 'bid'])->latest('id')->first() : null;
    $ask = $ask?->alive() ? $ask : null;
    $action = $ask ? '/reprice-asks/'.$ask->id : null;
@endphp
@if ($ask?->isCountered())
    <x-offer.answer-card {{ $attributes }} caption="Контрпредложение" :sum="$ask->counter_price"
        :sub="'ваша цена '.Money::rub((int) $ask->offered_price)" :due="$ask->due_at" :action="$action"
        agree-do="agree" decline-do="refuse" decline="Не согласен" :agree-ask="'Согласиться на '.Money::rub((int) $ask->counter_price).'?'"
        agree-text="Счёт и договор обновятся" decline-ask="Не соглашаться?" :decline-text="'В силе ваша цена '.Money::rub((int) $ask->offered_price)" error="reprice"/>
@elseif ($ask?->isOffered())
    <div {{ $attributes->class('counter-card') }}>
        <div class="text-sm text-accent-text">Новая цена отправлена</div>
        <div class="nums mt-1 text-[32px] font-bold leading-tight">{{ Money::rub((int) $ask->offered_price) }}</div>
        <p class="mt-2 text-sm text-ink-muted">Ждём решения</p>
    </div>
@elseif ($ask)
    @php $p = $ask->pairs(); $was = $ask->amountWas(); $low = $errors->has('low'); @endphp
    <div {{ $attributes->class('counter-card') }}>
        <div class="text-sm text-accent-text">{{ $ask->shownAs() === 'reward' ? 'Вознаграждение изменится' : 'Цена изменилась' }}</div>
        <div class="mt-2 flex flex-col gap-1">
            <div class="flex items-baseline gap-3"><span class="w-14 shrink-0 text-ink-muted">Было</span><span class="nums">{{ $p['was'] }}</span></div>
            <div class="flex items-baseline gap-3"><span class="w-14 shrink-0 text-ink-muted">Стало</span><span class="nums font-bold">{{ $p['now'] }}</span></div>
        </div>
        @if ($was)<p class="nums mt-2 text-sm text-ink-muted">Ваша цена подтверждения {{ Money::rub($was) }}</p>@endif
        <form method="post" action="{{ $action }}" class="mt-4 flex flex-col gap-2">
            @csrf<input type="hidden" name="do" value="price"><input type="hidden" name="low_ok" value="{{ $low ? '1' : '' }}">
            <x-ui.field name="price" label="Новая цена подтверждения, ₽" :id="'reprice-price-'.$ask->id" :value="old('price')" required/>
            @foreach (['amount', 'reprice', 'reprice_price'] as $key)@error($key)<p class="text-sm text-danger">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="btn btn-accent w-full">{{ $low ? 'Да, отправить' : 'Отправить цену' }}</button>
        </form>
        <form method="post" action="{{ $action }}" class="mt-2" data-turbo-confirm="Отказаться?" data-turbo-confirm-text="{{ $ask->declineMeans() }}" data-turbo-confirm-label="Отказываюсь">
            @csrf<input type="hidden" name="do" value="decline">
            <button type="submit" class="btn btn-quiet w-full">Отказываюсь</button>
        </form>
        <p class="mt-2 text-sm">Ответьте до <span class="nums">{{ Ago::html($ask->due_at->setTimezone('Europe/Moscow'), true, words: false) }}</span></p>
    </div>
@endif
