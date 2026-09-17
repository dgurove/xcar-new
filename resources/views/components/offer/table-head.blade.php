{{-- Шапка таблицы предложений; столбцы те же, что в x-offer.table-row. --}}
@props(['gallery' => false])
<tr>
    <th>№</th>
    <th class="grow">Марка, модель</th>
    <th>Состояние</th>
    <th class="num"><span class="hidden sm:inline">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</span></th>
    <th class="num hidden sm:table-cell">Цена</th>
    <th class="num">Прошло</th>
</tr>
