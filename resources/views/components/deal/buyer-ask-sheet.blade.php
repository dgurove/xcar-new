{{-- «Кто покупатель?» — сразу после «Беру в работу» (10.11.2026, владелец: «после нажатия сразу окно, в котором можно указать
     покупателя, ну или потом на странице сделки»): открывает флеш `open_sheet` от `Cabinet\DealController::answer` или
     `?buyer=1` (уведомление, Telegram). Физлицо, ИП или организация — сразу поля, выбор из своих, «Укажу потом» — дело остаётся. --}}
@props(['deal'])
@php $open = session('open_sheet') === 'deal-buyer-ask-'.$deal->id || (request()->boolean('buyer') && $deal->buyer_asked_at); @endphp
<div class="contents" data-controller="sheet">
    <x-ui.sheet :id="'deal-buyer-ask-'.$deal->id" title="Кто покупатель?" :open="$open">
        <div class="flex flex-col gap-4">
            <p class="text-ink-muted">Страховая попросит реквизиты, отправим сразу</p>
            <x-deal.buyer-kind :deal="$deal"/>
            {{-- Выбор — та же шторка блока «Покупатель» на странице (`buyer:open`): второй копии формы с теми же id здесь нет. --}}
            <div class="list"><button type="button" class="row w-full text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="buyer:open"><x-ui.row-icon name="users" size="s"/><span class="min-w-0 flex-1">Выбрать из моих покупателей</span><x-ui.chevron/></button></div>
            <div class="sheet-foot"><x-ui.button type="button" variant="secondary" block data-action="sheet#close">Укажу потом</x-ui.button></div>
        </div>
    </x-ui.sheet>
</div>
