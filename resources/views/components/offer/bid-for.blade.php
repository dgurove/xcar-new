{{-- «+ За менеджера» (05.10.2026, владелец: менеджер без интернета): админ вносит подтверждение сам — менеджер поиском,
     для покупателя ценой или в гараж, комментарий. Встаёт в список с «внёс …», принимают обычным «Принять»
     (`PlaceBidFor`, в обход приёма и порога). В подсказке поля цены — минимальная, от которой отталкиваться.
     07.10.2026: «для покупателя / в гараж» сверху, цена первой строкой, поля строками (.fields), кнопка .sheet-foot. --}}
@props(['offer'])
@php
    $id = 'bid-for-'.$offer->number;
    $min = $offer->minBid();
    $garage = $offer->garage_allowed;
@endphp
<div class="contents" data-controller="sheet">
    <x-ui.button type="button" size="sm" variant="ghost" class="self-start" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> За менеджера</x-ui.button>
    <x-ui.sheet :id="$id" title="Подтверждение за менеджера" :open="$errors->hasAny(['manager_id', 'amount']) && old('bid_for') == $offer->id">
        <form method="post" action="/offers/{{ $offer->number }}/confirmations" class="flex flex-col gap-3" data-controller="reveal">
            @csrf<input type="hidden" name="bid_for" value="{{ $offer->id }}">
            @if ($garage)
                <div class="segment">
                    <label><input type="radio" name="kind" value="buyer" @checked(old('kind', 'buyer') === 'buyer') data-action="reveal#pick"><span>Для покупателя</span></label>
                    <label><input type="radio" name="kind" value="garage" @checked(old('kind') === 'garage') data-action="reveal#pick"><span>В гараж</span></label>
                </div>
            @endif
            <div class="fields">
                <div class="contents" data-reveal-target="pane" data-reveal-key="buyer" @if (old('kind') === 'garage') hidden @endif>
                    <x-ui.field name="amount" label="Цена, ₽" inputmode="decimal" autocomplete="off" :value="old('amount')"
                        :placeholder="$min ? 'Минимальная '.\App\Support\Money::nums($min) : null" data-controller="digits" data-action="input->digits#format" id="{{ $id }}-amount"/>
                </div>
                <x-ui.combobox name="manager_id" label="Менеджер" url="/reference/managers" placeholder="Имя или телефон" :value="old('manager_id')"/>
                <x-ui.field name="comment" label="Комментарий" :value="old('comment')" placeholder="Звонил, без интернета" id="{{ $id }}-comment"/>
            </div>
            <div class="sheet-foot"><x-ui.button type="submit" block>Внести подтверждение</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>
