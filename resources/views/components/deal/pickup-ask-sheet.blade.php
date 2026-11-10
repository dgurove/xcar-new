{{-- «Попросить вывоз…» (10.11.2026, владелец: «непонятно, что эта кнопка делает»; было «Нужен вывоз»): шторка — что будет
     после нажатия, строками, и сама просьба (`RequestPickup`). Открывает кнопка с data-action="sheet#open" в том же
     data-controller="sheet". Что будет — так и устроено: исполнителя назначает логистика (`NotifyLogistics::requested`),
     привёз к менеджеру — шаг получения проходит сам (`SyncHandover::pressSale`). --}}
@props(['deal', 'back' => null])
<x-ui.sheet :id="'pickup-ask-'.$deal->id" title="Попросить вывоз">
    <div class="list">
        <div class="row"><x-ui.row-icon name="cat-truck" tone="soft" size="s"/><span class="min-w-0">Логистика XCar назначит исполнителя</span></div>
        <div class="row"><x-ui.row-icon name="map-pin" tone="soft" size="s"/><span class="min-w-0">Исполнитель заберёт автомобиль и привезёт вам</span></div>
        <div class="row"><x-ui.row-icon name="check" tone="soft" size="s"/><span class="min-w-0">Шаг «Автомобиль забрал» пройдёт сам, когда привезут</span></div>
    </div>
    <form method="post" action="/deals/{{ $deal->id }}/pickup" class="sheet-foot mt-5 flex flex-col gap-2">
        @csrf
        @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
        <x-ui.button block>Попросить вывоз</x-ui.button>
        <x-ui.button type="button" variant="ghost" block data-action="sheet#close">Отмена</x-ui.button>
    </form>
</x-ui.sheet>
