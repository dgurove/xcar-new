{{-- «Опубликовать» с выбором (владелец 03.10.2026): сейчас, в ближайший слот (по умолчанию) или в следующий — строками
     с датой, кнопка называет исход (подпись меняется по отмеченной строке, `.publish-choice` в app.css). Своей формой
     (`action`: выход маршрута) или полями чужой (`form`: редактор, `submit` — then=open). Срок страховой раньше слота —
     дата красным: таймер черновика уведёт предложение раньше. Без offer — пачкой (галочки «Оцененных» и «Публикации»,
     `form` — их форма); move — перенос стоящих в слоте: «Перенести на завтра в 16:00». «Другой день» — календарь (`Slots::DAY`). --}}
@props(['offer' => null, 'id', 'action' => null, 'form' => null, 'submit' => [], 'title' => 'Опубликовать', 'move' => false])
@php
    $choices = \App\Offers\Slots::choices();
    $late = fn ($at) => $offer && $at && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($at);
@endphp
<x-ui.sheet :id="$id" :title="$title">
    @if ($action)<form method="post" action="{{ $action }}" class="publish-choice flex flex-col gap-4">@csrf @else<div class="publish-choice flex flex-col gap-4">@endif
        <div class="list">
            @foreach ($choices as $c)
                <label class="row row-check">
                    <span class="min-w-0 flex-1">
                        <span class="block">{{ $c['title'] }}</span>
                        @if ($c['label'])<span @class(['row-sub', 'text-danger' => $late($c['at'])])>{{ $c['label'] }}</span>@endif
                    </span>
                    <span class="check"><input type="radio" name="when" value="{{ $c['when'] }}" @if ($form) form="{{ $form }}" @endif @checked($c['when'] === \App\Offers\Slots::NEAREST)></span>
                </label>
            @endforeach
            {{-- «Другой день» (13.10.2026): слот 16:00 выбранного числа, календарь ниже. --}}
            <label class="row row-check">
                <span class="min-w-0 flex-1">Другой день</span>
                <span class="check"><input type="radio" name="when" value="{{ \App\Offers\Slots::DAY }}" @if ($form) form="{{ $form }}" @endif></span>
            </label>
            {{-- «Срочно» (12.10.2026, владелец): сейчас, всем сразу, громко, свой срок приёма. Только одному предложению. --}}
            @if ($offer && ! $move)
                <label class="row row-check">
                    <x-ui.icon name="fire" fill class="size-5 shrink-0 text-fire"/>
                    <span class="min-w-0 flex-1">Срочно</span>
                    <span class="check"><input type="radio" name="when" value="{{ \App\Offers\Slots::URGENT }}" @if ($form) form="{{ $form }}" @endif></span>
                </label>
            @endif
        </div>
        <div class="publish-day fields">
            <x-ui.field name="day" :id="$id.'-day'" label="День" type="date" :value="\App\Offers\Slots::dayDefault()->toDateString()" :min="\App\Offers\Slots::nearest()->toDateString()" :form="$form"/>
        </div>
        @if ($offer && ! $move)
            <div class="urgent-until fields">
                <x-ui.field name="urgent_until" :id="$id.'-urgent-until'" label="Приём до" type="datetime-local" :value="\App\Offers\Slots::urgentDefault()->format('Y-m-d\TH:i')" :min="now()->addMinutes(30)->format('Y-m-d\TH:i')" :form="$form"/>
            </div>
        @endif
        {{-- Шторка закрывается сразу: ошибка поля (номер убытка, фото) должна быть видна, а не под шторкой. --}}
        <x-ui.button block :form="$form" :name="array_key_first($submit)" :value="$submit ? reset($submit) : null" data-action="sheet#close">
            @foreach ($choices as $c)<span data-when="{{ $c['when'] }}">{{ $c['at'] ? ($move ? 'Перенести на ' : 'Опубликовать ').\App\Offers\Slots::phrase($c['at']) : 'Опубликовать сейчас' }}</span>@endforeach
            <span data-when="{{ \App\Offers\Slots::DAY }}">{{ $move ? 'Перенести' : 'Опубликовать' }} в {{ sprintf('%02d:00', (int) config('xcar.slot_hour')) }}</span>
            <span data-when="{{ \App\Offers\Slots::URGENT }}">Опубликовать срочно</span>
        </x-ui.button>
    @if ($action)</form>@else</div>@endif
</x-ui.sheet>
