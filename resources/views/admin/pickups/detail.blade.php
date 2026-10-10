{{-- Карточка вывоза в «Работе → Вывозе»: кадры, название, кто вывозит и куда; путь вывоза с кнопками (`x-route.path`:
     «Забрал» ответственного сотрудник жмёт за него) и «Кто и куда вывозит» шторкой. Страница — редактор предложения. --}}
@php
    $position = $offer->position(\App\Workflow\Track::Service);
    $editable = ! $offer->pickedUp();
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$offer->number" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :action="false">
        {{-- Кто и куда — фразой («Иван М., к менеджеру»), не двумя капсулами (10.10.2026). --}}
        <x-slot:line>
            <span>{{ $offer->evacuator ? 'вывозит '.$offer->evacuator->shortName() : 'вывозим мы' }}</span>
            <span>{{ $offer->pickupDestination() === \App\Offers\Destination::Keeper && $offer->garageCar ? 'в гараж, '.$offer->keeper()?->shortName() : mb_strtolower($offer->pickupDestination()->label()) }}</span>
        </x-slot:line>
        <x-slot:actions>
            @if ($editable)<button type="button" class="pill" data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-{{ $offer->number }}:open">Кто и куда вывозит</button>@endif
        </x-slot:actions>
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="mt-3">{{ $errors->first('exit') }}</x-ui.flash>@endif
        <div class="mt-4"><x-route.path :offer="$offer" :position="$position"/></div>
        @if ($offer->evacuator)<x-chat.staff-line :offer="$offer" :user="$offer->evacuator" class="mt-4"/>@endif
    </x-ui.row-card>
    @if ($editable)
        <div data-controller="sheet" data-action="pickup-{{ $offer->number }}:open@window->sheet#open" class="contents">
            <x-ui.sheet id="pickup-{{ $offer->number }}" title="Вывоз" :open="$errors->hasAny(['evacuator_id', 'evacuation_to'])">
                <x-offer.pickup-form :offer="$offer" :managers="$managers" prefix="work-pickup-{{ $offer->number }}"/>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.detail>
