{{-- Таблица ТС в ДКП юрлица и в акте — одна. --}}
@php $pts = $doc->pts(); @endphp
<table class="car">
    <tr><td>Марка, модель ТС</td><td>{{ $doc->v($doc->carName()) }}</td></tr>
    <tr><td>Идентификационный номер (VIN)</td><td>{{ $doc->v($doc->offer->vin) }}</td></tr>
    <tr><td>Год изготовления ТС</td><td>{{ $doc->v($doc->offer->year) }}</td></tr>
    <tr><td>Цвет</td><td>{{ $doc->v($doc->color()) }}</td></tr>
    <tr><td>{{ $pts && $pts['kind'] === 'ЭПТС' ? 'ЭПТС' : 'Серия, № ПТС' }}</td><td>{{ $doc->v($pts ? $pts['text'] : null) }}</td></tr>
</table>
