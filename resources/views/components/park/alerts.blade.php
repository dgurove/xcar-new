{{-- Предупреждения по ТС у наименования: ставки нет, парковки нет, VIN, письма. Плашками — в карточке и окошке;
     plain — цветным словом в строке таблицы, где плашки на каждой третьей строке превращались в стену.
     place=false — «Нет парковки» не пишем: строка и так стоит в группе «Без парковки»; skip — чего не писать
     словом (таблица: «Нет типа» — знак вопроса на месте иконки типа). --}}
@props(['vehicle', 'plain' => false, 'place' => true, 'skip' => []])
@php
    $alerts = collect(\App\Park\Alerts::of($vehicle))->when(! $place, fn ($a) => $a->reject(fn ($x) => $x['label'] === 'Нет парковки'))
        ->reject(fn ($x) => in_array($x['label'], $skip, true));
    // В строке это слово внутри текста: строчными, «VIN» остаётся как есть.
    $word = fn (string $label) => $label === 'VIN отсутствует' ? 'нет VIN' : mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
@endphp
@foreach ($alerts as $alert)
    @if ($plain)
        <span class="{{ $alert['tone'] === 'danger' ? 'text-danger' : 'text-urgent' }}">{{ $word($alert['label']) }}</span>
    @else
        <span class="tag {{ $alert['tone'] === 'danger' ? 'tag-danger' : 'tag-urgent' }}">{{ $alert['label'] }}</span>
    @endif
@endforeach
