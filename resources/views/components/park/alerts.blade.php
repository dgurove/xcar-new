{{-- Предупреждения по ТС у наименования: ставки нет, парковки нет, VIN, письма. Плашками — в карточке и окошке;
     plain — цветным словом в строке таблицы, где плашки на каждой третьей строке превращались в стену.
     place=false — «Нет парковки» не пишем: строка и так стоит в группе «Без парковки». --}}
@props(['vehicle', 'plain' => false, 'place' => true])
@php
    $alerts = collect(\App\Park\Alerts::of($vehicle))->when(! $place, fn ($a) => $a->reject(fn ($x) => $x['label'] === 'Нет парковки'));
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
