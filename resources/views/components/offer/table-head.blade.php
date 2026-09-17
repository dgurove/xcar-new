{{-- Шапка таблицы предложений; столбцы те же, что в x-offer.table-row. --}}
@props(['gallery' => false])
<tr>
    <th class="w-14 sm:w-16">№</th>
    <th>Марка, модель</th>
    <th class="w-24 sm:w-36">Состояние</th>
    <th class="num w-7 sm:w-36"><span class="hidden sm:inline">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</span></th>
    <th class="num hidden w-52 sm:table-cell">Цена</th>
    <th class="num w-16 sm:w-20">Прошло</th>
</tr>
