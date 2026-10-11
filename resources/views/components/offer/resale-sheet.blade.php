{{-- «Вернуть в продажу» из сделки или гаража (11.10.2026, `ReturnToSale`): шторка с переоценкой — закупочная, заявленная
     (пусто — от закупочной вверх до тысячи) и цена продажи, подставлены нынешние. Открывает её событие resale:open
     (пункт «···» редактора, «Действий» карточки, строка внизу карточки сделки и гаража) — шторка стоит вне меню: поповер
     прячет вложенное. Сделка отменяется, строка гаража уходит — подтверждение словами. --}}
@props(['offer'])
@php
    use App\Support\Money;
    $n = $offer->number;
    $confirm = $offer->state === \App\Offers\OfferState::Garage || \App\Garage\Car::where('offer_id', $offer->id)->exists()
        ? 'ТС уйдёт из гаража менеджера, гаражная сделка отменится, неоплаченные счета аннулируются'
        : 'Подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются';
@endphp
<div data-controller="sheet" data-action="resale:open@window->sheet#open" class="contents">
    <x-ui.sheet id="resale-{{ $n }}" title="Вернуть в продажу" :open="$errors->hasAny(['asking_price', 'floor_price', 'publish_price'])">
        <form method="post" action="/offers/{{ $n }}/resale" class="flex flex-col gap-4" data-turbo-confirm="Вернуть в продажу?" data-turbo-confirm-text="{{ $confirm }}" data-turbo-confirm-label="Вернуть">
            @csrf
            <div class="fields">
                <x-ui.field name="floor_price" label="Закупочная, ₽" :value="old('floor_price', $offer->floor_price ? Money::nums($offer->floor_price) : null)" data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
                <x-ui.field name="publish_price" label="Заявленная, ₽" :value="old('publish_price', $offer->publish_price ? Money::nums($offer->publish_price) : null)" :placeholder="$offer->floor_price ? Money::nums(\App\Offers\Offer::declaredFrom($offer->floor_price)) : null" data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
                <x-ui.field name="asking_price" label="Цена продажи, ₽" :value="old('asking_price', $offer->asking_price ? Money::nums($offer->asking_price) : null)" required data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
            </div>
            <div class="sheet-foot"><x-ui.button block>Вернуть в продажу</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>
