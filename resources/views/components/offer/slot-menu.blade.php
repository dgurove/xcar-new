{{-- Предложение в слоте: капсула «Выйдет сегодня в 16:00» — она же меню: опубликовать сейчас, перенести на другой слот,
     убрать из слота. Шапка редактора и окошко строки CRM. --}}
@props(['offer'])
@php
    use App\Offers\Slots;
    $other = $offer->slot_at->equalTo(Slots::nearest()) ? Slots::NEXT : Slots::NEAREST;
    $id = 'slot-menu-'.$offer->number;
@endphp
<div class="contents" data-controller="menu">
    <button type="button" class="pill pill-open" data-action="menu#toggle" aria-haspopup="menu" aria-controls="{{ $id }}">Выйдет {{ Slots::phrase($offer->slot_at) }} <x-ui.icon name="chevron-down" class="size-4"/></button>
    <div id="{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
        @foreach ([Slots::NOW => 'Опубликовать сейчас', $other => 'Перенести на '.mb_strtolower(Slots::label(Slots::at($other)))] as $when => $label)
            <form method="post" action="/offers/{{ $offer->number }}/state">
                @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ $when }}">
                <button class="menu-item w-full" role="menuitem" data-action="menu#close">{{ $label }}</button>
            </form>
        @endforeach
        <form method="post" action="/offers/{{ $offer->number }}/unschedule">
            @csrf<button class="menu-item w-full" role="menuitem" data-action="menu#close">Убрать из слота</button>
        </form>
    </div>
</div>
