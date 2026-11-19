{{-- Контрпредложение (26.10.2026, начальник; `MakeCounter`): своя цена в ответ на подтверждение — крупно по центру, как
     «Ссылка на оплату»; чипы «+5 000», «+10 000», «Продажная»; под суммой — насколько выше цены менеджера и зона цены словом
     (админу порог знать можно). Живое у машины одно: висит у другого — строкой, что оно снимется. Кнопка — слот, или открывает событие `event` (пункт шторки строки подтверждения). --}}
@props(['bid', 'offer', 'event' => null])
@php
    use App\Support\Money;
    $id = 'counter-'.$bid->id;
    $asking = (int) $offer->asking_price;
    $other = $offer->bids->first(fn ($b) => ! $b->is($bid) && $b->hasPendingCounter());
@endphp
<div class="contents" data-controller="sheet" @if ($event) data-action="{{ $event }}:open@window->sheet#open" @endif>
    {{ $slot }}
    <x-ui.sheet :id="$id" title="Контрпредложение">
        <form method="post" action="/confirmations/{{ $bid->id }}/counter" class="flex flex-col gap-4" data-controller="counter"
              data-counter-bid-value="{{ (int) $bid->amount }}" data-counter-min-value="{{ (int) $offer->minBid() }}" data-counter-hard-value="{{ (int) $offer->hardMinBid() }}">
            @csrf
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/><span class="text-ink-muted">предложил <span class="nums">{{ Money::rub($bid->amount) }}</span></span></div>
            <label class="flex flex-col items-center gap-1">
                <span class="flex items-baseline justify-center gap-2">
                    <input name="counter_amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Цена, ₽" placeholder="0"
                           value="{{ old('counter_amount', $asking > $bid->amount ? Money::nums($asking) : '') }}" data-controller="digits"
                           data-action="input->digits#format input->counter#update" data-counter-target="input" autofocus>
                    <span class="text-xl text-ink-muted">₽</span>
                </span>
                <span class="nums text-sm text-ink-muted" data-counter-target="diff"></span>
                <span class="text-sm" data-counter-target="zone"></span>
                @error('counter')<span class="text-sm text-danger">{{ $message }}</span>@enderror
                @error('counter_amount')<span class="text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <div class="sheet-chips justify-center">
                <button type="button" class="chip nums" data-action="counter#set" data-counter-add-param="5000">+5 000</button>
                <button type="button" class="chip nums" data-action="counter#set" data-counter-add-param="10000">+10 000</button>
                @if ($asking > $bid->amount)<button type="button" class="chip nums" data-action="counter#set" data-counter-value-param="{{ $asking }}">Продажная {{ Money::nums($asking) }}</button>@endif
            </div>
            <div class="fields"><x-ui.field name="counter_comment" label="Комментарий" :value="old('counter_comment')" placeholder="Необязательно" id="{{ $id }}-comment"/></div>
            {{-- Резерву при идущей сделке (19.11.2026): согласие сразу передаёт машину ему (`AnswerCounter`). --}}
            @if ($offer->deal)<p class="text-sm text-urgent">Согласится — ТС сразу уйдёт ему, сделка {{ $offer->deal->buyer?->shortName() ?? 'текущего покупателя' }} отменится</p>@endif
            @if ($other)<p class="text-sm text-ink-muted">Контрпредложение {{ $other->user->shortName() }} (<span class="nums">{{ Money::rub($other->counter_amount) }}</span>) будет отозвано</p>@endif
            <x-ui.sheet-foot type="submit" data-counter-target="submit">Отправить {{ $bid->user->shortName() }}</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
