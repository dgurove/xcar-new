{{-- Куда берёт менеджер: «Для покупателя за N ₽» или «В гараж на подготовку» (без цены, по закупочной — её на сайте
     не видно). Шторка внутри формы подтверждения: кнопки — её отправка с `kind`, гаражной цена не нужна (formnovalidate).
     Открывает bid_controller вместо отправки, когда у предложения стоит «Можно в гараж». --}}
@props(['offer'])
<div class="contents" data-controller="sheet" data-bid-target="choice">
    <x-ui.sheet id="bid-choice-{{ $offer->number }}" title="Подтвердить предложение?">
        <div class="flex flex-col gap-4">
            <button type="submit" name="kind" value="buyer" class="btn btn-accent w-full nums" data-bid-target="buyer"><span>Для покупателя<span data-bid-target="sum" hidden></span></span></button>
            <button type="submit" name="kind" value="garage" formnovalidate class="btn btn-quiet relative w-full">В гараж на подготовку<x-ui.burst/></button>
        </div>
    </x-ui.sheet>
</div>
