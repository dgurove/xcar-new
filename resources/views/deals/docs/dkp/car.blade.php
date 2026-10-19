{{-- Таблица ТС в ДКП юрлица и в акте — одна. --}}
@php $_ = fn ($v, $n = 24) => filled($v) ? e($v) : str_repeat('_', $n); $pts = $doc->pts(); @endphp
<table class="car">
    <tr><td>Марка, модель ТС</td><td>{!! $_($doc->carName()) !!}</td></tr>
    <tr><td>Идентификационный номер (VIN)</td><td>{!! $_($doc->offer->vin) !!}</td></tr>
    <tr><td>Год изготовления ТС</td><td>{!! $_($doc->offer->year, 8) !!}</td></tr>
    <tr><td>Цвет</td><td>{!! $_($doc->color()) !!}</td></tr>
    <tr><td>{{ $pts && $pts['kind'] === 'ЭПТС' ? 'ЭПТС' : 'Серия, № ПТС' }}</td><td>{!! $_($pts ? $pts['text'] : null) !!}</td></tr>
</table>
