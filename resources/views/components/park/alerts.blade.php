{{-- Предупреждения по ТС: ставки нет, парковки нет, VIN, письма, расхождение с документами. Плашками — в карточке и деле;
     plain — в строке таблицы одним значком после названия (30.10.2026, владелец: слова «в документе иначе» в строке — «в
     нормальных приложениях делают по-другому»): треугольник цветом худшего, при нескольких — с числом; нажатие — список
     полными фразами (`Park\Alerts::hint`) всплывающим меню, как у значка связи; строку и карточку не открывает.
     place=false — «Нет парковки» не пишем: строка и так стоит в группе «Без парковки»; skip — чего не писать
     (таблица: «Нет типа» — знак вопроса на месте иконки типа); at — место значка в строке, для id меню. --}}
@props(['vehicle', 'plain' => false, 'place' => true, 'skip' => [], 'at' => 'row'])
@php
    $alerts = collect(\App\Park\Alerts::of($vehicle))->when(! $place, fn ($a) => $a->reject(fn ($x) => $x['label'] === 'Нет парковки'))
        ->reject(fn ($x) => in_array($x['label'], $skip, true))->values();
@endphp
@if ($plain)
    @if ($alerts->isNotEmpty())
        @php
            $danger = $alerts->contains(fn ($a) => $a['tone'] === 'danger');
            $hints = $alerts->map(fn ($a) => \App\Park\Alerts::hint($a['label']));
            // Строка рисует значок дважды (ПК и третья строка телефона) — у меню свой id на каждое место.
            $id = 'alerts-'.$at.'-'.$vehicle->id;
        @endphp
        <span class="row-alert" data-controller="menu">
            <button type="button" class="row-alert-btn {{ $danger ? 'text-danger' : 'text-urgent' }}" data-action="menu#toggle:stop:prevent" title="{{ $hints->implode(', ') }}" aria-label="{{ $hints->implode(', ') }}" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon name="alert" class="size-full"/>@if ($alerts->count() > 1)<span class="row-alert-n">{{ $alerts->count() }}</span>@endif</button>
            <span id="{{ $id }}" class="menu row-alert-menu" popover data-menu-target="list" role="menu" data-action="click->menu#close:stop">
                @foreach ($alerts as $i => $alert)
                    <span class="menu-item"><span class="row-alert-dot {{ $alert['tone'] === 'danger' ? 'bg-danger' : 'bg-urgent' }}"></span>{{ $hints[$i] }}</span>
                @endforeach
            </span>
        </span>
    @endif
@else
    @foreach ($alerts as $alert)
        <span class="tag {{ $alert['tone'] === 'danger' ? 'tag-danger' : 'tag-urgent' }}">{{ $alert['label'] }}</span>
    @endforeach
@endif
