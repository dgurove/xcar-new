{{-- Письма рядом с формой: от 1024 стоят второй колонкой сетки, на телефоне — шторка, которую открывает
     x-mail.aside-button (событие letters:open). Сетка у вызывающего: lg:grid-cols-[minmax(0,1fr)_26rem]. --}}
@props(['messages', 'base' => '/mail'])
<div data-controller="sheet" data-sheet-inflow-value="(min-width: 1024px)" data-action="letters:open@window->sheet#open" class="contents">
    <x-ui.sheet id="letters" :title="'Письма '.count($messages)" inflow {{ $attributes->merge(['class' => 'lg:self-start']) }}>
        <x-mail.panel :messages="$messages" :base="$base"/>
    </x-ui.sheet>
</div>
