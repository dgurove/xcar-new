{{-- «Другой день…» (13.10.2026, владелец: «сегодня, завтра и через несколько дней — там выбор, какого числа»): календарь и
     кнопка; выйдет в слот 16:00 выбранного дня (`Slots::DAY`). Открывает событие `publish-day:open` (пункт «Действий»
     карточки, «···» редактора); шторка вне меню — поповер прячет вложенное. --}}
@props(['offer'])
@php $n = $offer->number; $hour = sprintf('%02d:00', (int) config('xcar.slot_hour')); @endphp
<div data-controller="sheet" data-action="publish-day:open@window->sheet#open" class="contents">
    <x-ui.sheet id="publish-day-{{ $n }}" title="Опубликовать в другой день">
        <form method="post" action="/offers/{{ $n }}/state" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="state" value="open">
            <input type="hidden" name="when" value="{{ \App\Offers\Slots::DAY }}">
            <div class="fields">
                <x-ui.field name="day" :id="'publish-day-field-'.$n" label="День" type="date" required
                    :value="\App\Offers\Slots::dayDefault()->toDateString()" :min="\App\Offers\Slots::nearest()->toDateString()"/>
            </div>
            <div class="sheet-foot"><x-ui.button block><x-ui.icon name="calendar" class="size-5"/> Опубликовать в {{ $hour }}</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>
