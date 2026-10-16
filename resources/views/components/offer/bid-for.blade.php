{{-- «+ За менеджера» (05.10.2026, владелец: менеджер без интернета): админ вносит подтверждение сам — менеджер поиском,
     для покупателя ценой или в гараж, комментарий. Встаёт в список с «внёс …», принимают обычным «Принять»
     (`PlaceBidFor`, в обход приёма и порога). В подсказке поля цены — минимальная, от которой отталкиваться.
     07.10.2026: «для покупателя / в гараж» сверху, поля строками (.fields), кнопка .sheet-foot. 15.10.2026: менеджер первой
     строкой — «за кого» первый вопрос, и подсказки на телефоне встают вверху листа, а не у клавиатуры. --}}
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
                <x-ui.segment name="kind" :options="['buyer' => 'Для покупателя', 'garage' => 'В гараж']" :value="old('kind', 'buyer')" pick/>
            @endif
            <div class="fields">
                <x-ui.combobox name="manager_id" label="Менеджер" url="/reference/managers" placeholder="Имя или телефон" :value="old('manager_id')" :id="$id.'-manager'"/>
                <div class="contents" data-reveal-target="pane" data-reveal-key="buyer" @if (old('kind') === 'garage') hidden @endif>
                    <x-ui.field name="amount" label="Цена, ₽" :value="old('amount')"
                        :placeholder="$min ? 'Минимальная '.\App\Support\Money::nums($min) : null" id="{{ $id }}-amount"/>
                </div>
                <x-ui.field name="comment" label="Комментарий" :value="old('comment')" placeholder="Звонил, без интернета" id="{{ $id }}-comment"/>
            </div>
            <x-ui.sheet-foot type="submit">Внести подтверждение</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
