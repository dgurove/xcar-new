{{-- «Другой день…» (13.10.2026, владелец: «сегодня, завтра и через несколько дней — там выбор, какого числа»): календарь с
     временем (по умолчанию 16:00, другое время — свой слот) и кнопка (`Slots::DAY`). Открывает событие `publish-day:open` (пункт «Действий»
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
                <x-ui.field name="day" :id="'publish-day-field-'.$n" label="Когда" type="datetime-local" required :default-time="$hour"
                    :value="\App\Offers\Slots::dayDefault()->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
            </div>
            <x-ui.sheet-foot><x-ui.icon name="calendar" class="size-5"/> Опубликовать</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
