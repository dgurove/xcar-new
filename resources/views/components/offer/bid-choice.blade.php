{{-- Куда берёт менеджер: «Для покупателя за N ₽» или «В гараж на подготовку» (без цены, по закупочной — её на сайте
     не видно). Шторка внутри формы подтверждения: кнопки — её отправка с `kind`, гаражной цена не нужна (formnovalidate).
     Открывает bid_controller вместо отправки, когда у предложения стоит «Можно в гараж», а админу — всегда: сверху
     «Менеджер», за кого он подтверждает (08.10.2026); без менеджера кнопки не жмутся.
     «Для покупателя» жмётся всегда (12.11.2026, Сахиб по 2611093770 не вписал цену, а кнопка молча стояла серой; владелец:
     «чтоб даже самый дундук справился»): цена есть — уходит, нет — второй панелью той же шторки шаг «Цена для покупателя»:
     цена машины крупно, как в окне (`x-offer.price-head`), чипы цены и скидок, поле и «Подтвердить за N ₽». Поле шага и
     поле формы — одно значение (`bid#askInput`). --}}
@props(['offer', 'garage' => true, 'staff' => false])
@php
    $title = $staff ? 'За какого менеджера?' : 'Подтвердить предложение?';
    $asking = (int) $offer->asking_price;
    $discounts = $asking ? $offer->bidDiscounts(auth()->user()) : [];
@endphp
<div class="contents" data-controller="sheet" data-bid-target="choice" data-title="{{ $title }}">
    <x-ui.sheet id="bid-choice-{{ $offer->number }}" :title="$title">
        <div class="flex flex-col gap-4" data-bid-target="choicePane">
            @if ($staff)
                <div class="fields"><x-people.pick name="manager_id" label="Менеджер" kind="manager" :people="\App\Support\People::managers()" title="Менеджер" placeholder="Выбрать менеджера"/></div>
            @endif
            <button type="submit" name="kind" value="buyer" class="btn btn-accent w-full nums" data-bid-target="buyer" data-action="bid#buyer"><span>Для покупателя<span data-bid-target="sum" hidden></span></span></button>
            @if ($garage)
                <button type="submit" name="kind" value="garage" formnovalidate class="btn btn-quiet relative w-full" data-bid-target="garage">В гараж на подготовку<x-ui.burst/></button>
            @endif
        </div>
        <div class="flex flex-col gap-4" data-bid-target="pricePane" hidden>
            <x-offer.price-head :price="\App\Offers\PriceView::for($offer, auth()->user())"/>
            @if ($asking)
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-s btn-quiet nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
                    @foreach ($discounts as $percent => $price)
                        <button type="button" class="btn btn-s btn-quiet" data-action="bid#set" data-bid-amount-param="{{ $price }}">−{{ $percent }}%</button>
                    @endforeach
                </div>
            @endif
            <div class="field">
                <label for="bid-ask-{{ $offer->number }}" class="field-label">Цена, ₽</label>
                <input id="bid-ask-{{ $offer->number }}" type="text" inputmode="decimal" enterkeyhint="go" autocomplete="off" class="field-input nums text-lg" data-bid-target="ask" data-action="input->bid#askInput">
                <p class="mt-1.5 text-sm text-danger" data-bid-target="askError" hidden></p>
            </div>
            <button type="submit" name="kind" value="buyer" class="btn btn-accent w-full nums" data-bid-target="askSubmit"><span>Подтвердить<span data-bid-target="sum" hidden></span></span></button>
        </div>
    </x-ui.sheet>
</div>
