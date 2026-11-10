{{-- Новая цена после переоценки в CRM (10.11.2026, `AnswerReprice`): что с вопросом менеджеру — словом и кнопками справа.
     Ход его — «Цена за менеджера…» (сказал устно: шторка с ценой, сразу принимается) и «Отказался»; назвал цену — «Принять»,
     «Контрпредложение…» (шторка с ценой выше его) и «Отказать» (сделка отменяется); наше контрпредложение висит — за него
     «Согласен» / «Не согласен». Одна разметка у шага «Переоценка» (`x-route.reprice-step`) и строк `x-offer.reprice-asks`. --}}
@props(['ask', 'offer'])
@php
    use App\Support\Money;
    $action = '/offers/'.$offer->number.'/reprice-asks/'.$ask->id;
    $who = $ask->user?->shortName() ?? 'менеджер';
    $pill = 'btn btn-s btn-quiet shrink-0 !h-8 rounded-full !px-3.5';
    $sheetId = 'reprice-ask-'.$ask->id;
@endphp
<span {{ $attributes->class('flex shrink-0 flex-wrap items-center gap-2') }}>
    @if ($ask->isOffered())
        <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="Принять {{ Money::rub((int) $ask->offered_price) }}?" data-turbo-confirm-text="Сумма сделки станет {{ Money::rub((int) $ask->offered_price) }}, закупочная {{ Money::rub($ask->to_price) }}; договор и счета обновятся" data-turbo-confirm-label="Принять">
            @csrf<input type="hidden" name="do" value="accept"><button type="submit" class="btn btn-s btn-accent shrink-0 !h-8 rounded-full !px-3.5">Принять</button>
        </form>
        <span class="contents" data-controller="sheet">
            <button type="button" class="{{ $pill }}" data-action="sheet#open">Контрпредложение</button>
            <x-ui.sheet :id="$sheetId.'-counter'" title="Контрпредложение">
                <form method="post" action="{{ $action }}" class="flex flex-col gap-4">
                    @csrf<input type="hidden" name="do" value="counter">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$ask->user" full/><span class="text-ink-muted">предлагает <span class="nums">{{ Money::rub((int) $ask->offered_price) }}</span></span></div>
                    <label class="flex flex-col items-center gap-1">
                        <span class="flex items-baseline justify-center gap-2">
                            <input name="counter_price" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Цена, ₽" placeholder="0"
                                   value="{{ old('counter_price') }}" data-controller="digits" data-action="input->digits#format" autofocus>
                            <span class="text-xl text-ink-muted">₽</span>
                        </span>
                        <span class="nums text-sm text-ink-muted">закупочная {{ Money::rub($ask->to_price) }}@if ($offer->asking_price), продажа {{ Money::rub((int) $offer->asking_price) }}@endif</span>
                        @foreach (['counter_price', 'reprice'] as $key)@error($key)<span class="text-sm text-danger">{{ $message }}</span>@enderror @endforeach
                    </label>
                    <x-ui.sheet-foot type="submit">Отправить {{ $who }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </span>
        <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="Отказать {{ $who }}?" data-turbo-confirm-text="{{ $ask->declineMeans() }}" data-turbo-confirm-label="Отказать">
            @csrf<input type="hidden" name="do" value="decline"><button type="submit" class="{{ $pill }}">Отказать</button>
        </form>
    @elseif ($ask->isCountered())
        <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="За менеджера: согласен на {{ Money::rub((int) $ask->counter_price) }}?" data-turbo-confirm-label="Согласен">
            @csrf<input type="hidden" name="do" value="agree"><button type="submit" class="{{ $pill }}">Согласен</button>
        </form>
        <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="За менеджера: не согласен?" data-turbo-confirm-text="В силе его цена {{ Money::rub((int) $ask->offered_price) }}" data-turbo-confirm-label="Не согласен">
            @csrf<input type="hidden" name="do" value="refuse"><button type="submit" class="{{ $pill }}">Не согласен</button>
        </form>
    @else
        <span class="contents" data-controller="sheet">
            <button type="button" class="{{ $pill }}" data-action="sheet#open">Цена за менеджера</button>
            <x-ui.sheet :id="$sheetId.'-price'" title="Цена за менеджера">
                <form method="post" action="{{ $action }}" class="flex flex-col gap-4">
                    @csrf<input type="hidden" name="do" value="price">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$ask->user" full/>@if ($was = $ask->amountWas())<span class="text-ink-muted">была <span class="nums">{{ Money::rub($was) }}</span></span>@endif</div>
                    <label class="flex flex-col items-center gap-1">
                        <span class="flex items-baseline justify-center gap-2">
                            <input name="reprice_price" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Цена, ₽" placeholder="0"
                                   value="{{ old('reprice_price') }}" data-controller="digits" data-action="input->digits#format" autofocus>
                            <span class="text-xl text-ink-muted">₽</span>
                        </span>
                        <span class="nums text-sm text-ink-muted">закупочная {{ Money::rub($ask->to_price) }}@if ($offer->asking_price), продажа {{ Money::rub((int) $offer->asking_price) }}@endif</span>
                        @foreach (['reprice_price', 'reprice', 'amount'] as $key)@error($key)<span class="text-sm text-danger">{{ $message }}</span>@enderror @endforeach
                    </label>
                    <x-ui.sheet-foot type="submit">{{ $ask->deal_id ? 'Принять' : 'Записать' }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </span>
        <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="За менеджера: отказался?" data-turbo-confirm-text="{{ $ask->declineMeans() }}" data-turbo-confirm-label="Отказался">
            @csrf<input type="hidden" name="do" value="decline"><button type="submit" class="{{ $pill }}">Отказался</button>
        </form>
    @endif
</span>
