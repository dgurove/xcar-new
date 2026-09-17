{{-- Шапка таблицы предложений; столбцы те же, что в x-offer.table-row. --}}
@props(['gallery' => false])
<tr>
    <th class="hidden w-14 sm:table-cell">№</th>
    <th>Машина</th>
    <th class="w-28 sm:w-36">Состояние</th>
    <th class="num w-9 sm:w-36"><span class="hidden sm:inline">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</span></th>
    <th class="num hidden w-52 sm:table-cell">Цена</th>
    <th class="num w-20">Прошло</th>
</tr>
