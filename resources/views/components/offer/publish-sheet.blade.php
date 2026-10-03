{{-- «Опубликовать» с выбором (владелец 03.10.2026): сейчас, в ближайший слот (по умолчанию) или в следующий — строками
     с датой, кнопка называет исход (подпись меняется по отмеченной строке, `.publish-choice` в app.css). Своей формой
     (`action`: выход маршрута) или полями чужой (`form`: редактор, `submit` — then=open). Срок страховой раньше слота —
     дата красным: таймер черновика уведёт предложение раньше. --}}
@props(['offer', 'id', 'action' => null, 'form' => null, 'submit' => []])
@php
    $choices = \App\Offers\Slots::choices();
    $late = fn ($at) => $at && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($at);
@endphp
<x-ui.sheet :id="$id" title="Опубликовать">
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
        </div>
        {{-- Шторка закрывается сразу: ошибка поля (номер убытка, фото) должна быть видна, а не под шторкой. --}}
        <x-ui.button block :form="$form" :name="array_key_first($submit)" :value="$submit ? reset($submit) : null" data-action="sheet#close">
            @foreach ($choices as $c)<span data-when="{{ $c['when'] }}">{{ $c['at'] ? 'Опубликовать '.\App\Offers\Slots::phrase($c['at']) : 'Опубликовать сейчас' }}</span>@endforeach
        </x-ui.button>
    @if ($action)</form>@else</div>@endif
</x-ui.sheet>
