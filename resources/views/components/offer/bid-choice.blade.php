{{-- Куда берёт менеджер: «Для покупателя за N ₽» или «В гараж на подготовку» (без цены, по закупочной — её на сайте
     не видно). Шторка внутри формы подтверждения: кнопки — её отправка с `kind`, гаражной цена не нужна (formnovalidate).
     Открывает bid_controller вместо отправки, когда у предложения стоит «Можно в гараж», а админу — всегда: сверху
     «Менеджер», за кого он подтверждает (08.10.2026); без менеджера кнопки не жмутся. --}}
@props(['offer', 'garage' => true, 'staff' => false])
<div class="contents" data-controller="sheet" data-bid-target="choice">
    <x-ui.sheet id="bid-choice-{{ $offer->number }}" :title="$staff ? 'За какого менеджера?' : 'Подтвердить предложение?'">
        <div class="flex flex-col gap-4">
            @if ($staff)
                <div class="fields"><x-people.pick name="manager_id" label="Менеджер" kind="manager" :people="\App\Support\People::managers()" title="Менеджер" placeholder="Выбрать менеджера"/></div>
            @endif
            <button type="submit" name="kind" value="buyer" class="btn btn-accent w-full nums" data-bid-target="buyer"><span>Для покупателя<span data-bid-target="sum" hidden></span></span></button>
            @if ($garage)
                <button type="submit" name="kind" value="garage" formnovalidate class="btn btn-quiet relative w-full" data-bid-target="garage">В гараж на подготовку<x-ui.burst/></button>
            @endif
        </div>
    </x-ui.sheet>
</div>
