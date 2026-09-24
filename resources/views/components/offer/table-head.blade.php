{{-- Шапка таблицы предложений (видна от 640); столбцы те же, что в x-offer.table-row. --}}
@props(['gallery' => false])
<tr>
    <th class="grow">Марка, модель</th>
    <th class="cell-dim hidden sm:table-cell">№</th>
    <th class="hidden sm:table-cell">Состояние</th>
    <th class="num hidden sm:table-cell">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</th>
    <th class="num">Цена</th>
    <th class="num col-peek-hide hidden sm:table-cell">Прошло</th>
</tr>
